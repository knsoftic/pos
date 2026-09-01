<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a sale was paid for (#17, #19).
 *
 * A TABLE, not a column, because split payment is ordinary: half on a card, the
 * rest in cash, and a shop needs to reconcile each method separately at the end
 * of the day. One `payment_method` column on the sale would make that
 * impossible and would have to be widened the first time somebody paid two ways.
 *
 * `credit` is a method here like any other, but it is the one that takes no
 * money: it charges the customer's account instead (#40, #41). The service knows
 * the difference; the table does not need to.
 *
 * Rows are append-only in spirit — a mistaken payment is corrected by voiding
 * the sale, not by editing what was recorded as handed over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // From config('pos.payment_methods') — a shop adds JazzCash or
            // EasyPaisa without a deploy (#17, #190).
            $table->string('method', 40)->index();

            $table->decimal('amount', 14, 2);

            // Card slip number, transfer reference, QR transaction id (#18).
            $table->string('reference', 100)->nullable();

            $table->timestamp('received_at');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['sale_id', 'id']);
            $table->index(['business_id', 'method', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
