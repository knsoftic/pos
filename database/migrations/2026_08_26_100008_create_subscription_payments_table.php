<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received for a subscription (#82). Financial records are never deleted —
 * a mistake is corrected with a `refunded` row, so the trail stays intact. #198
 *
 * `business_id` is denormalised (also reachable via the subscription) because
 * every operator report and every tenant-side billing screen filters by tenant,
 * and joining through subscriptions on every one of those reads is wasteful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // Validated against config('subscription.payment_methods') rather
            // than an enum, so a deployment can accept local rails (JazzCash,
            // EasyPaisa) without a code change. #190
            $table->string('method', 40);

            // App\Enums\PaymentStatus — system state, hence a fixed enum.
            $table->string('status', 20)->index();

            // Bank/gateway transaction reference.
            $table->string('reference')->nullable();

            $table->timestamp('paid_at')->nullable()->index();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
