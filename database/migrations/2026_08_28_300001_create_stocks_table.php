<?php

use App\Services\InventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is on the shelf, per branch (#28, #136).
 *
 * ⚠️ THIS TABLE IS A CACHE, NOT THE TRUTH. `stock_movements` is the truth: an
 * append-only ledger of every change ever made. This row is the running balance,
 * maintained inside the same transaction as the movement that changed it, so
 * reads (the POS asking "can I sell this?") are one indexed lookup instead of a
 * SUM over history. {@see InventoryService::recalculate()} rebuilds
 * it from the ledger, which is both the repair tool and the proof that the two
 * can never legitimately disagree.
 *
 * ONE ROW PER (branch, product, variant). A variable product's stock lives on its
 * variants — that is what makes "3 red larges left at the High Street shop" a
 * question with an answer.
 *
 * THE `variant_key` COLUMN exists because SQL unique indexes do not constrain
 * NULLs: two rows with product_variant_id NULL are "distinct" to MySQL, so a
 * plain unique index would happily allow two stock rows for the same simple
 * product. The generated column collapses NULL to 0 so the index actually bites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            // Quantities are decimal, never integer: 1.25 kg is a real quantity.
            // Four places is enough for grams-in-kilograms without inviting
            // floating-point nonsense.
            $table->decimal('quantity', 16, 4)->default(0);

            /*
             | Weighted average cost of what is currently on hand, in the base
             | unit. Recalculated on every incoming movement:
             |
             |   new_avg = (old_qty × old_avg + in_qty × in_cost) ÷ (old_qty + in_qty)
             |
             | Average rather than FIFO because it survives a stock take, a
             | negative balance and a correction without needing layers to
             | unwind. FIFO/batch costing rides on top later (#34) for shops that
             | need it, and will not change what this column means.
             */
            $table->decimal('average_cost', 14, 4)->default(0);

            // When the shelf was last touched — cheap answer for "is this figure
            // stale?" without opening the ledger.
            $table->timestamp('last_movement_at')->nullable();

            $table->timestamps();

            // Collapse NULL → 0 so the unique index below actually constrains
            // simple products. See the class docblock.
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(product_variant_id, 0)');

            $table->unique(['branch_id', 'product_id', 'variant_key'], 'stocks_unique_shelf');

            // "Everything in this branch", "this product across branches", and
            // the low-stock sweep all hit these. #167
            $table->index(['business_id', 'branch_id']);
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
