<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central limit registry (#8, #129) — the countable quotas: products, customers,
 * employees, branches, POS counters, invoices per month, storage…
 *
 * NULL SEMANTICS (used consistently across plan_limit and the per-business
 * overrides, so there is exactly one way to say each thing):
 *
 *   row missing   → inherit the next level down (business → plan → this default)
 *   value = NULL  → UNLIMITED
 *   value = 0     → nothing may be created (quota effectively off)
 *   value = 500   → 500 allowed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limits', function (Blueprint $table) {
            $table->id();

            // Stable machine key, e.g. `limits.products`.
            $table->string('code', 80)->unique();

            $table->string('name');
            $table->string('description')->nullable();

            // Shown after the number in usage meters, e.g. "350 / 500 products".
            $table->string('unit', 30)->nullable();

            $table->string('group', 40)->default('general')->index();

            // Fallback when neither the business nor the plan specifies a value.
            // NULL here means unlimited — see the note above.
            $table->unsignedBigInteger('default_value')->nullable();

            // Distinguishes "default_value is NULL because unlimited" from
            // "…because the operator never set one". Only when this is true is
            // a NULL default treated as unlimited; otherwise it means 0. Keeps
            // enforcement fail-closed for quotas nobody has configured yet.
            $table->boolean('default_unlimited')->default(false);

            // Quotas that reset every month (invoices/month) vs. absolute
            // totals (products). Drives how usage is counted.
            $table->boolean('is_monthly')->default(false);

            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limits');
    }
};
