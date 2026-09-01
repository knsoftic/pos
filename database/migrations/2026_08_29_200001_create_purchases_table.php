<?php

use App\Services\PurchaseService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases from suppliers (#35, #36).
 *
 * A purchase is a DOCUMENT, and the numbers on it are derived from its lines —
 * which is why `subtotal`, `tax_total` and `total` are stored rather than
 * recomputed on every read: an invoice printed last March must still show what
 * it showed last March, even if a product's cost has changed since. They are
 * written by {@see PurchaseService} from the lines, never edited
 * by hand.
 *
 * `paid_amount` is likewise a cache over the supplier's ledger, kept in step
 * inside the same transaction, so "what is still outstanding on this bill" is
 * one read rather than a join across the account.
 *
 * ⚠️ NO document-level discount or shipping column, deliberately. Every figure
 * on this row is the sum of line figures, so nothing has to be apportioned back
 * across lines when a delivery arrives in instalments — an allocation puzzle
 * that would have to round somewhere, and would round differently on partial
 * receipts than on full ones. Carriage goes on as a line.
 *
 * Branch-scoped: goods land somewhere, and that somewhere is a shelf (#136).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Where the goods land.
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a supplier with purchase history is archived,
            // never removed (#104) — the bill has to keep resolving to someone.
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            // The shop's own PO number, allocated centrally so it is unique.
            $table->string('reference', 40);

            // The supplier's invoice number, when they send one.
            $table->string('supplier_invoice_no', 60)->nullable();

            // App\Enums\PurchaseStatus
            $table->string('status', 20)->index();

            $table->date('order_date');
            $table->date('expected_date')->nullable();

            // Money. Four places on unit costs (a case price divided down is
            // often fractional); two on document totals, because that is what is
            // actually paid.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // How much of this bill has been settled. Cache over the ledger.
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Only an untouched draft is ever deleted; anything posted is
            // cancelled instead (#133, #198).
            $table->softDeletes();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'supplier_id']);
            $table->index(['branch_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
