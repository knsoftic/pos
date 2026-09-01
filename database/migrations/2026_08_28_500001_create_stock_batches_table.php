<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batches and expiry dates (#34).
 *
 * WHY THIS IS A SEPARATE TABLE, and not two columns on `stocks`:
 *
 *   A shelf can hold the same product from three different deliveries, expiring
 *   in three different weeks. "How many yoghurts do we have" and "how many are
 *   still good on Friday" are different questions, and only a per-batch row can
 *   answer the second one. `stocks` stays the fast total; this is the breakdown.
 *
 * NOT EVERY PRODUCT NEEDS THIS. A shop selling both milk and phone chargers
 * should not be made to type a batch number for the chargers, so tracking is a
 * per-product flag (`products.tracks_batches`) gated by the plan's expiry
 * feature. A product without it simply has no rows here and behaves exactly as
 * it did before this table existed.
 *
 * CONSUMPTION IS FEFO — first EXPIRY, first out — not FIFO. For perishables the
 * oldest delivery is not always the one going off first (a later delivery can
 * have a shorter life), and selling the longer-dated stock first is how a shop
 * ends up throwing away the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            // The supplier's lot number, when there is one. A batch can be
            // identified by its expiry alone — plenty of goods are.
            $table->string('batch_number', 60)->nullable();

            // Date, not datetime: nothing expires at 14:32.
            $table->date('expiry_date')->nullable()->index();

            $table->decimal('quantity', 16, 4)->default(0);

            // What this particular delivery cost. Kept per batch because two
            // batches of the same product routinely cost different amounts.
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            // The FEFO sweep and the expiry report both read "this shelf, by
            // expiry date, still holding something".
            $table->index(['branch_id', 'product_id', 'expiry_date'], 'stock_batches_fefo_index');
            $table->index(['business_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
