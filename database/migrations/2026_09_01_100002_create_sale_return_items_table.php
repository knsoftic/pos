<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of goods coming back (#53).
 *
 * Each line points at the SALE LINE it reverses, which is what makes "you
 * cannot return more than was sold" a question the service can answer: three
 * were bought, one already came back, so two is the most that can come back now.
 *
 * ⚠️ `restock` IS PER LINE, and it is the decision that matters most here.
 * A customer returning an unopened box and a customer returning a smashed one
 * are both owed their money, but only one of those goes back on the shelf.
 * Restocking everything by default would quietly inflate stock every time
 * something came back broken — a shop would find the error at its next count and
 * have no idea where it came from.
 *
 * The price and the COST are copied from the sale line rather than looked up:
 * goods come back at what they were sold for, and the profit reversal has to use
 * the cost that applied then (#52).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();

            // The line being reversed.
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('description');

            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            // Does this go back on the shelf? See the class docblock.
            $table->boolean('restock')->default(true);

            // Why not, when it does not — "smashed", "opened", "past its date".
            $table->string('condition_note')->nullable();

            $table->timestamps();

            $table->index(['sale_return_id', 'id']);
            $table->index(['sale_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
