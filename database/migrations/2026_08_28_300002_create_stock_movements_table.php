<?php

use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory ledger (#29, #30) — the actual truth about stock.
 *
 * APPEND-ONLY. There is no `updated_at` because a movement is never edited: a
 * mistake is corrected by posting an opposite movement, exactly as a financial
 * record is voided rather than deleted (#133, #198). That is what makes the
 * ledger evidence rather than a guess.
 *
 * `quantity` is SIGNED — positive in, negative out — so a running balance is a
 * plain SUM and no reader has to know which types add and which subtract.
 * {@see StockMovementType::direction()} decides the sign on write.
 *
 * `balance_after` is stamped at write time inside the same locked transaction.
 * It costs one column and it means the ledger screen can show a running balance
 * without recomputing history for every row — and it is what makes a corrupted
 * balance visible instead of silent.
 *
 * `reference_type` / `reference_id` point at whatever caused the movement — a
 * sale, a purchase, a transfer, a stock take. Nullable, because an adjustment
 * typed in by a manager references nothing but its own reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            // App\Enums\StockMovementType
            $table->string('type', 20)->index();

            // Signed: + in, − out.
            $table->decimal('quantity', 16, 4);

            // What one unit cost on the way in. Zero for outgoing movements —
            // a sale consumes value at the cost already on the books.
            $table->decimal('unit_cost', 14, 4)->default(0);

            // The shelf figure immediately after this movement was applied.
            $table->decimal('balance_after', 16, 4);

            $table->nullableMorphs('reference');

            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            // Who did it. Nullable for system-generated movements (a scheduled
            // job, a data import) — but never nullable in meaning: the audit log
            // still carries the actor.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // created_at only: an immutable record has nothing to update.
            $table->timestamp('created_at')->useCurrent();

            // The ledger screen reads "this product, newest first"; the
            // recalculation reads "this shelf, oldest first". Both are covered.
            $table->index(['business_id', 'product_id', 'created_at']);
            $table->index(['branch_id', 'product_id', 'product_variant_id', 'id'], 'stock_movements_shelf_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
