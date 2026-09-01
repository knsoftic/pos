<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The party ledger (#41, #42) — every movement on a customer's or supplier's
 * account, in the accounting format a bookkeeper expects: date, description,
 * debit, credit, running balance.
 *
 * APPEND-ONLY, and there is no `updated_at`. A financial line is never edited;
 * a mistake is corrected by posting its opposite, which is the same rule as the
 * stock ledger and as #133/#198 require. That is what makes this evidence.
 *
 * ONE TABLE FOR BOTH PARTIES, via a morph. The alternative — two near-identical
 * tables — would mean two copies of the running-balance logic, and the copy that
 * is used less would be the one that drifts. What differs between a customer and
 * a supplier is what the balance MEANS, not how it is computed, so the meaning
 * lives on the party and the arithmetic lives here.
 *
 * DEBIT AND CREDIT ARE SEPARATE NON-NEGATIVE COLUMNS rather than one signed
 * amount. Two reasons: the screen is an accounting statement and needs them in
 * two columns anyway, and a signed amount invites a reader to guess which sign
 * means what. `balance_after` carries the sign, and it is stamped inside the
 * locked transaction so the statement never has to recompute history.
 *
 * ⚠️ NOT BRANCH-SCOPED. `branch_id` records WHERE it happened, but a customer's
 * account is business-wide (#137): someone must be able to settle at the retail
 * park what they ran up on the High Street.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // customers / suppliers.
            $table->morphs('party');

            // Where it happened. Informational — see the class docblock.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // App\Enums\LedgerEntryType
            $table->string('type', 20)->index();

            // Exactly one of these is non-zero on any given line.
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);

            // The account's balance immediately after this line. Signed.
            $table->decimal('balance_after', 14, 2);

            // The sale, purchase or payment behind it. Null for an opening
            // balance or a manual adjustment, which reference only their reason.
            $table->nullableMorphs('reference');

            // The document number a human would quote — invoice no, receipt no.
            $table->string('reference_no', 60)->nullable();

            $table->string('description')->nullable();

            // How the money moved, for settlements: cash, card, bank transfer.
            $table->string('payment_method', 40)->nullable();

            /*
             | The date the entry BELONGS TO, which is not always the date it was
             | typed in: a shop entering Friday's takings on Monday needs the
             | statement to read Friday. `created_at` still records when it was
             | actually written, so the two questions stay separable.
             */
            $table->date('entry_date');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // The statement reads "this party, in order"; the recalculation reads
            // the same thing oldest-first. Both are covered.
            $table->index(['party_type', 'party_id', 'id'], 'ledger_entries_party_index');
            $table->index(['business_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
