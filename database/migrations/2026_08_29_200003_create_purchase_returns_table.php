<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods sent back to a supplier (#37).
 *
 * A SEPARATE DOCUMENT, not a negative purchase. Two reasons:
 *   - A return happens on its own date, for its own reason, and often covers
 *     part of one delivery. Folding it into the original would rewrite a
 *     document that has already been acted on (#198).
 *   - The supplier asks "what did you send back and when"; that question needs
 *     a record with its own number.
 *
 * It always points at the purchase it came from, so a shop can never return more
 * than it received — the service checks each line against what actually arrived.
 *
 * Unlike a purchase there is no draft/ordered dance: goods either went back or
 * they did not. Creating one posts everything at once, in one transaction —
 * stock out, supplier credited (#119).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // The delivery the goods came from. restrictOnDelete because a
            // purchase with a return against it is not deletable in any case.
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('reference', 40);

            $table->date('return_date');

            // Why it went back. Required by the form — an unexplained return is
            // the entry a supplier will query first.
            $table->string('reason');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'return_date']);
            $table->index(['purchase_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
