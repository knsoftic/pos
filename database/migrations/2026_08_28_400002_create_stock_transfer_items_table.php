<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One product on one transfer (#32).
 *
 * TWO QUANTITIES, and the gap between them is the reason this table is not just
 * a quantity column:
 *
 *   quantity_sent     — what the source branch says it put in the van.
 *   quantity_received — what the destination says came out of it. NULL until
 *                       someone actually counts, which is different from zero.
 *
 * When they differ, the difference is not silently reconciled. The goods left
 * one shelf and never reached the other, so the ledger shows exactly that, and
 * the transfer carries the discrepancy on its face. A system that quietly made
 * the numbers agree would be hiding the only signal that something went wrong
 * on the journey.
 *
 * `unit_cost` is snapshotted at send time so the receiving branch values the
 * stock at what it actually cost, not at whatever the catalogue says later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();

            // Lines live and die with their transfer — and only a draft can be
            // deleted at all.
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->decimal('quantity_sent', 16, 4);
            $table->decimal('quantity_received', 16, 4)->nullable();

            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->string('notes')->nullable();

            $table->timestamps();

            // Same NULL trap as the stocks table: a unique index does not
            // constrain NULLs, so without collapsing the variant to 0 a simple
            // product could appear on two lines of one transfer — and then
            // "how many were counted in?" has two answers.
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(product_variant_id, 0)');

            // A product may appear only once per transfer.
            $table->unique(['stock_transfer_id', 'product_id', 'variant_key'], 'transfer_items_unique_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
