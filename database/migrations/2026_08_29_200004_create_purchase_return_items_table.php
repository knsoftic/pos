<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of goods going back to a supplier (#37).
 *
 * Each line points at the PURCHASE LINE it reverses, which is what lets the
 * service check "you received 12, you have already sent back 5, so 7 is the most
 * you can return now". Without that link a shop could return the same delivery
 * twice over and the supplier's account would quietly go the wrong way.
 *
 * The cost is copied from the purchase line rather than re-entered: goods go
 * back at what they were bought for, not at today's price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();

            // The line being reversed.
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('description');

            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->timestamps();

            $table->index(['purchase_return_id', 'id']);
            $table->index(['purchase_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
