<?php

use App\Services\PurchaseService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line on a purchase (#35).
 *
 * TWO QUANTITIES, not one: what was ORDERED and what has actually been
 * RECEIVED so far. The gap between them is the whole reason `Partial` exists —
 * a delivery that came up short is ordinary trade, and the shop needs to see
 * which lines still have goods outstanding.
 *
 * `quantity_received` accumulates across receipts, so a line can be delivered in
 * instalments. {@see PurchaseService::receive()} posts only the
 * NEW quantity each time, which is what stops a second receipt double-counting.
 *
 * THE PRICE IS SNAPSHOTTED. `unit_cost` is what this supplier charged on this
 * date, not what the product's cost happens to be today — a bill has to keep
 * reading the way it read when it was issued.
 *
 * Batch and expiry ride on the line for batch-tracked products (#34): the
 * delivery note is where a lot number actually comes from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Lines live and die with their purchase — and a purchase is only
            // ever deleted while it is an untouched draft.
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            // What the product was called when it was bought. A product renamed
            // next year must not silently rewrite last year's paperwork.
            $table->string('description');

            $table->decimal('quantity_ordered', 16, 4);
            $table->decimal('quantity_received', 16, 4)->default(0);

            $table->decimal('unit_cost', 14, 4);

            // Absolute, not a percentage: suppliers give "500 off this line", and
            // storing the percentage would reintroduce a rounding argument.
            $table->decimal('discount_amount', 14, 2)->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);

            // Derived from the four columns above, stored so the document keeps
            // its own arithmetic.
            $table->decimal('line_total', 14, 2)->default(0);

            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('notes')->nullable();

            $table->timestamps();

            $table->index(['purchase_id', 'id']);
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
