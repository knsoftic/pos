<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense categories — DYNAMIC, per tenant (#43, #190).
 *
 * Not an enum and not a config list. A pharmacy books "dispensary licence" and
 * a restaurant books "gas cylinders"; neither of those belongs in the other's
 * dropdown, and neither would ever be worth a deployment to add. The shop
 * invents its own filing, the same way it invents its own product categories.
 *
 * The tenant still starts with a usable set (see ExpenseService::seedDefaults)
 * because an empty dropdown on the first expense form is a dead end.
 *
 * ARCHIVED, NEVER DELETED once used (#104): the expenses filed under a category
 * are what a P&L reads, and deleting the heading would leave last quarter's
 * figures with nowhere to sit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Two shops may both have "Rent"; one shop may not have it twice.
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
