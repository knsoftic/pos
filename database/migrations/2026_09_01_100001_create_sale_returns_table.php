<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods coming back from a customer (#53).
 *
 * A SEPARATE DOCUMENT, never an edit of the sale. The original invoice was
 * printed, handed over and possibly filed by a tax authority; rewriting it a
 * week later would make the shop's copy and the customer's copy disagree
 * (#133, #198). The return is its own record with its own number, pointing at
 * the sale it reverses.
 *
 * THE MONEY CAN GO TWO WAYS, and often both at once:
 *   refunded_amount — handed back: out of the drawer, off a card
 *   credited_amount — put on the customer's account, reducing what they owe
 *
 * A walk-in can only be refunded, because there is no account to credit. An
 * account customer who still owes money is usually better served by a credit,
 * and the shop decides which at the counter — so both columns exist rather than
 * one "amount" plus a flag that would have to be interpreted everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // The sale being reversed. restrictOnDelete is belt-and-braces: a
            // sale is never deleted in the first place.
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            // Copied from the sale rather than joined, because a return against
            // a walk-in sale has no customer and still needs to answer "whose?".
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Where the goods came back to — not necessarily where they were
            // sold, since a chain lets people return to any branch.
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);

            $table->date('return_date');

            // Why it came back. Required by the form: an unexplained return is
            // the first entry an auditor asks about, and the reason is also what
            // tells the shop whether the goods can be sold again.
            $table->string('reason');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // What the goods cost the shop, carried from the SALE's snapshot so
            // the profit reversal uses the cost that applied when it sold, not
            // today's (#52).
            $table->decimal('cost_total', 14, 4)->default(0);

            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->decimal('credited_amount', 14, 2)->default(0);

            // How the refund was handed back, when there was one.
            $table->string('refund_method', 40)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'return_date']);
            $table->index(['sale_id', 'id']);
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
