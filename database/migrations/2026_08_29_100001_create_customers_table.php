<?php

use App\Services\CustomerLedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers (#39). Tenant-scoped — and deliberately NOT branch-scoped (#137).
 *
 * A customer belongs to the BUSINESS, not to the shop they first walked into.
 * Someone who bought at the High Street branch must be servable at the retail
 * park one, and their balance has to be the same number in both places. This is
 * the opposite decision to stock, which is per branch precisely because a shelf
 * is a physical thing in one building.
 *
 * `balance` IS A CACHE, exactly like `stocks.quantity`: the ledger is the truth,
 * this column is the running total maintained in the same transaction so that
 * "what does this customer owe" is one indexed read rather than a SUM over
 * years of history. {@see CustomerLedgerService::recalculate()}
 * rebuilds it, which is both the repair tool and the proof they agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Short reference the shop uses on paperwork. Generated when not
            // supplied, unique per business.
            $table->string('code', 30);

            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();

            // For invoices in jurisdictions that require it (#59).
            $table->string('tax_number', 60)->nullable();

            /*
             | How much they may owe at once (#40). The three-value convention,
             | same as everywhere else in this codebase:
             |   NULL → no ceiling (an account customer who is trusted)
             |   0    → no credit at all; cash only. THE DEFAULT, because handing
             |          out credit should be a decision somebody made.
             |   n    → that much and no further.
             */
            // Nullable BECAUSE null is a meaningful value here, not a missing
            // one — see the three-value convention above.
            $table->decimal('credit_limit', 14, 2)->nullable()->default(0);

            // Positive = the customer owes the business. Cache; see the docblock.
            $table->decimal('balance', 14, 2)->default(0);

            // Active / Blocked (#105). A blocked customer keeps every record and
            // every rupee of their balance — they simply cannot transact.
            $table->boolean('is_active')->default(true);
            $table->string('blocked_reason')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // A customer with history is archived, never destroyed (#104, #198).
            $table->softDeletes();

            $table->unique(['business_id', 'code']);

            // The POS searches by name and phone constantly (#167).
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
