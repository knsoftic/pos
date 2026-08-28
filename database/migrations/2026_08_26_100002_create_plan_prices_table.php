<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One price per plan per billing cycle (#175). A plan offers whichever cycles
 * the operator creates rows for — monthly only, yearly only, or all six.
 *
 * Why a separate table instead of price_monthly/price_yearly columns: adding a
 * new cycle then needs a migration, and "which cycles does this plan sell?"
 * becomes unanswerable. Rows also let a cycle be switched off (`is_active`)
 * without losing its price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // App\Enums\BillingCycle value.
            $table->string('billing_cycle', 20);

            // 0.00 is legitimate — that is exactly how a Free plan is expressed. #173
            $table->decimal('price', 12, 2)->default(0);

            // Only for the `custom` cycle: how many days the period lasts.
            $table->unsignedSmallInteger('custom_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A plan cannot list the same cycle twice. `custom_days` is part of
            // the key so a plan may sell several custom durations (30d / 45d).
            $table->unique(['plan_id', 'billing_cycle', 'custom_days'], 'plan_prices_unique_cycle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
