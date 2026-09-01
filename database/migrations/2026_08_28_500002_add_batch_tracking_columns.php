<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wires batches into the two tables that already existed (#34).
 *
 * `products.tracks_batches` — off by default, deliberately. Turning batch
 * tracking on for a whole catalogue would make every stock entry ask for a lot
 * number, including for the products that will never have one. A shop opts in
 * per product, and only if the plan includes expiry tracking.
 *
 * `stock_movements.stock_batch_id` — which batch this line took from or added
 * to. Nullable because most products never carry batches, and because a
 * movement made before a product was switched to batch tracking has no batch to
 * point at.
 *
 * ONE MOVEMENT NEVER SPANS TWO BATCHES. Selling six yoghurts that come from two
 * deliveries writes two ledger lines, not one line with a footnote — so every
 * line still answers "how many, from where, at what cost" on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_batches')->default(false)->after('track_inventory');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // nullOnDelete: losing a batch row must never delete ledger history.
            $table->foreignId('stock_batch_id')->nullable()->after('product_variant_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracks_batches');
        });
    }
};
