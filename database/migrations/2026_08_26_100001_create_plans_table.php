<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans (#7). Everything a plan *is* lives in the database — name,
 * description, badge, trial length, ordering, visibility — so the operator can
 * create/rename/reprice plans with no deploy. #190
 *
 * Deliberately NOT columns here:
 *   - prices        → `plan_prices` (one row per billing cycle, #175)
 *   - features      → `plan_feature` pivot (#9)
 *   - limits        → `plan_limit` pivot (#8)
 *
 * "Free" (#173) and "Lifetime" (#174) are not flags: a free plan is one whose
 * prices are 0, a lifetime plan is one that has a `lifetime` price row. Fewer
 * flags, fewer states that can disagree with each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Marketing ribbon on the pricing page, e.g. "Most Popular" (#7).
            $table->string('badge', 40)->nullable();

            // Free-trial length granted when this plan is assigned (#81).
            // 0 = no trial. Falls back to config('subscription.trial_days')
            // only when the operator leaves it null.
            $table->unsignedSmallInteger('trial_days')->nullable();

            // Days of continued access after expiry (#127). Null = use the
            // system default from config/subscription.php.
            $table->unsignedSmallInteger('grace_days')->nullable();

            // is_active  → may be assigned to businesses at all
            // is_public  → "Show on Website" toggle for the pricing page (#172)
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();
            // Plans referenced by a subscription are archived, never hard-deleted,
            // so historical invoices keep resolving. #104
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
