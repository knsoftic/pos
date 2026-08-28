<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business's subscription to a plan. Rows are APPEND-ONLY history (#176):
 * renewing, upgrading or downgrading creates a NEW row and stamps
 * `superseded_at` on the old one, so past billing is never rewritten. #198
 *
 * The CURRENT subscription is the row with `superseded_at IS NULL`
 * (see Subscription::scopeCurrent). The service layer enforces one per business
 * inside a transaction — MySQL cannot express that as a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // A plan in use may not be hard-deleted — it is archived instead, so
            // historical rows keep resolving to a real plan. #104
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            // App\Enums\BillingCycle. Snapshotted, along with price/currency,
            // because the plan's pricing may change after this sale.
            $table->string('billing_cycle', 20);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3);

            // App\Enums\SubscriptionStatus — the operator's INTENT. Whether the
            // subscription has actually run out is always derived from the dates
            // below (Subscription::effectiveStatus), never trusted from here.
            $table->string('status', 20)->index();

            $table->timestamp('starts_at');

            // NULL = never expires (lifetime plan). #174
            $table->timestamp('ends_at')->nullable()->index();

            // Set while the tenant is inside a free trial. #81
            $table->timestamp('trial_ends_at')->nullable();

            // Per-subscription grace override (#127). NULL → plan → config.
            $table->unsignedSmallInteger('grace_days')->nullable();

            // Replaced by a newer subscription (upgrade/downgrade/renew). #83
            $table->timestamp('superseded_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            // Operator-only note about this particular subscription.
            $table->text('notes')->nullable();

            // Null for public trial signups, which have no acting admin. #109
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            // Hot path: "the current subscription for this business", run on
            // essentially every tenant request.
            $table->index(['business_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
