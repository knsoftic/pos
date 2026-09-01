<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line on a sale (#14, #118).
 *
 * EVERYTHING IS SNAPSHOTTED — the description, the price, and the COST. The
 * first two because a receipt must keep reading the way it read when it was
 * printed; the third because profit is the difference between what something
 * sold for and what it cost AT THE TIME, and stock cost moves every time a
 * delivery arrives at a different price.
 *
 * Without `unit_cost` here, last month's margin would be recalculated against
 * this month's cost every time a report ran, and would quietly change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a product that has been sold is archived, never
            // removed (#104) — the invoice has to keep resolving.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('description');

            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 14, 2);

            // What it cost us, at this moment. See the class docblock.
            $table->decimal('unit_cost', 14, 4)->default(0);

            // Absolute, like a purchase line: shops give "50 off", and storing a
            // percentage would reintroduce a rounding argument.
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);

            $table->decimal('line_total', 14, 2)->default(0);

            $table->timestamps();

            $table->index(['sale_id', 'id']);
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
