<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variants of a variable product — size, colour, pack (#25).
 *
 * A variant is the thing that is actually sold and counted: it carries its own
 * SKU, barcode, price and (from the inventory tables) its own stock. The parent
 * product is the grouping the customer browses.
 *
 * The option set is JSON (`{"Size": "L", "Colour": "Red"}`) rather than an
 * attributes/values/pivot trio. It is called `options`, not `attributes`, on
 * purpose: `$attributes` is Eloquent's own internal property, and a column of
 * that name reads correctly from outside the model but silently returns the raw
 * attribute bag from inside it. Shops invent their own axes and rename them
 * constantly; three tables would buy referential tidiness at the cost of every
 * screen doing joins for data that is only ever read as a whole. The variant's
 * `name` is the rendered version, so nothing has to parse JSON to draw a list.
 *
 * `business_id` is stored even though it is reachable through the product: the
 * tenant scope must filter this table without a join, exactly as with counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Deleting a product takes its variants with it. Reachable only when
            // nothing references them — a product with history is archived.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // "Red / Large" — built from $options when the variant is saved.
            $table->string('name');

            $table->string('sku', 60);
            $table->string('barcode', 60)->nullable();

            $table->json('options')->nullable();

            $table->decimal('cost_price', 14, 4)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);

            $table->decimal('alert_quantity', 16, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // SKUs and barcodes share one namespace with products: scanning a
            // code at the till must never be ambiguous. Enforced across both
            // tables by ProductService, which allocates every code.
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
