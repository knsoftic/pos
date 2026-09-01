<?php

use App\Services\CashSessionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till's trading period — open the drawer, sell, count it back (#46, #139).
 *
 * WHY THIS EXISTS AT ALL: without it, "is the till short?" has no answer. A
 * session brackets a stretch of trading so the expected cash is calculable —
 * opening float, plus cash taken, less cash paid out — and can be set against
 * what somebody actually counted. The difference is the number a shop cares
 * about, and it is only meaningful because both ends were counted.
 *
 * ONE OPEN SESSION PER COUNTER. Two people trading into the same drawer with
 * two sets of figures would make both meaningless; the service enforces it, and
 * the partial index below cannot (MySQL has no partial unique index), so the
 * check lives in {@see CashSessionService} inside a transaction.
 *
 * `expected_cash` is a CACHE, maintained as cash sales land, so closing a till
 * does not have to sum a day of payments. `recalculate()` rebuilds it from the
 * payments themselves — the same repair-and-proof pattern as stock and ledgers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // The till itself. Nullable because a shop with one counter and no
            // interest in the feature should still be able to open a drawer.
            $table->foreignId('pos_counter_id')->nullable()->constrained()->nullOnDelete();

            // Who is on the till. Kept even if the account is later removed, so
            // an old cash-up still says who counted it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            // App\Enums\CashSessionStatus
            $table->string('status', 20)->index();

            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default(0);

            // Cash taken in and paid out during the session. Both maintained as
            // they happen, in the same transaction as the sale that caused them.
            $table->decimal('cash_sales', 14, 2)->default(0);
            $table->decimal('cash_refunds', 14, 2)->default(0);
            $table->decimal('cash_in', 14, 2)->default(0);
            $table->decimal('cash_out', 14, 2)->default(0);

            $table->timestamp('closed_at')->nullable();

            // What the drawer should hold, and what was actually in it.
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();

            // counted − expected. Positive is over, negative is short. Stored
            // rather than derived so a historical cash-up keeps its own answer
            // even if an old sale is later voided.
            $table->decimal('difference', 14, 2)->nullable();

            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['branch_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
