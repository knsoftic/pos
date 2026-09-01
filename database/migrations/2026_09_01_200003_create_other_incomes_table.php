<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that came in without anything being sold (#44).
 *
 * Scrap sold to the recycler, a sublet corner of the shop, a supplier's rebate,
 * insurance settling a claim. None of it is a sale: no stock left the shelf, so
 * it has no cost of goods and it must NOT land in revenue — putting it there
 * would inflate gross profit and quietly destroy the margin figure the owner
 * uses to price things.
 *
 * So it sits below the gross-profit line in the P&L (#45), next to expenses and
 * pulling the other way:
 *
 *      Revenue − COGS = GROSS PROFIT
 *      GROSS PROFIT − Expenses + Other income = NET PROFIT
 *
 * A SEPARATE TABLE rather than a signed amount on `expenses`: a table where the
 * meaning of a row depends on the sign of a column is one forgotten WHERE away
 * from reporting an expense as income. Two tables cannot make that mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Required for the same reason an expense's branch is: branch
            // statements have to add up to the business statement.
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // Cash received into an open till moves its `cash_in` (#46).
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->date('income_date')->index();

            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 40)->nullable();

            // Where it came from — free text, because the whole point of "other"
            // income is that it does not fit a fixed list.
            $table->string('source');
            $table->string('note')->nullable();

            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'income_date']);
            $table->index(['business_id', 'branch_id', 'income_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_incomes');
    }
};
