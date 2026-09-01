<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale (#21, #22, #118). The document the whole system exists to produce.
 *
 * NO SOFT DELETES, and that is the point: a sale is never deleted, only voided
 * (#133, #198). An invoice number that once existed must keep existing, because
 * somebody has the paper copy and a tax authority may ask about the gap.
 *
 * The totals are stored, not recomputed. A receipt printed in March has to keep
 * saying what it said in March even after a price change, a tax change or a
 * product being renamed — so every figure that appeared on it is frozen here and
 * on the lines.
 *
 * `paid_total` is a cache over `sale_payments`; `due_amount` is what went onto
 * the customer's account. Both are maintained inside the same transaction as the
 * payments themselves (#118), so a till can answer "what is outstanding" without
 * summing anything.
 *
 * Branch-scoped: a sale happens at a shop, and a manager sees their own (#48).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->foreignId('pos_counter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();

            // Null = walk-in (#146). Most shop sales have no named customer, and
            // forcing one would make every till operator invent something.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Format is configurable (#22); uniqueness is not.
            $table->string('invoice_no', 40);

            // App\Enums\SaleStatus
            $table->string('status', 20)->index();

            $table->timestamp('sold_at')->nullable();
            $table->date('sale_date')->nullable();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);

            // Rounding applied to the payable figure (#POS cash_rounding), kept
            // separately so the receipt can show it rather than hiding it inside
            // a total that then fails to add up.
            $table->decimal('rounding', 8, 2)->default(0);

            $table->decimal('total', 14, 2)->default(0);

            // What the cost of the goods was, snapshotted at the moment of sale.
            // Profit is a cost question, and today's cost is not the cost that
            // applied then (#52).
            $table->decimal('cost_total', 14, 4)->default(0);

            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('change_given', 14, 2)->default(0);

            // Charged to the customer's account rather than paid (#40, #41).
            $table->decimal('due_amount', 14, 2)->default(0);

            // Who sold it. Kept when the account goes.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->text('notes')->nullable();

            // Voiding (#198): the record stays, the postings are reversed.
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            // How many times the invoice has been reprinted (#143).
            $table->unsignedInteger('print_count')->default(0);

            $table->timestamps();

            $table->unique(['business_id', 'invoice_no']);

            // The three questions a shop asks constantly: today's takings, this
            // customer's history, this till's session (#167).
            $table->index(['business_id', 'sale_date']);
            $table->index(['branch_id', 'status', 'sold_at']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['cash_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
