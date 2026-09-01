<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the business spent that is not stock (#43).
 *
 * Stock bought for resale is a PURCHASE, not an expense — its cost reaches the
 * profit figure through COGS when the goods sell, not on the day the delivery
 * arrived. Rent, wages, electricity and the van's diesel are expenses: they hit
 * the month they belong to and never touch inventory. Keeping the two apart is
 * the whole reason a P&L can distinguish gross profit from net profit (#45).
 *
 * ================= WHY branch_id IS REQUIRED =================
 * An expense is paid by somewhere. Allowing a null branch would create a class
 * of costs that appear in the business P&L but in no branch P&L, so the branch
 * statements would silently fail to add up to the whole — and the first person
 * to notice would be the owner wondering why their shops' profits are better
 * than their business's. Head-office costs are booked to the main branch, which
 * is a decision the shop can see and argue with, rather than a hole.
 *
 * ================= THE ATTACHMENT =================
 * A receipt photo is the difference between a bookkeeping entry and something
 * an auditor will accept. Stored under the same upload policy as product images
 * (#101): content-checked, randomly named, never inside the web root.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a category holding expenses is archived, never
            // deleted, so this should be unreachable — belt and braces.
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            // A cash expense really does take money out of the drawer, so the
            // session it came from is recorded and its `cash_out` moves with it
            // (#46). Null for a bank transfer, or when no till is open.
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->date('expense_date')->index();

            $table->decimal('amount', 14, 2);

            // How it was paid, from the same vocabulary the till uses so a
            // day's cash movements can be reconciled from one list.
            $table->string('payment_method', 40)->nullable();

            // Who it was paid to, and their document number. Free text on
            // purpose: the landlord is not a supplier in the purchasing sense,
            // and giving every payee a ledger account would invent balances
            // nobody asked to keep.
            $table->string('payee')->nullable();
            $table->string('bill_no', 60)->nullable();

            $table->string('note')->nullable();

            // The receipt photo or PDF.
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'expense_category_id']);
            $table->index(['business_id', 'branch_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
