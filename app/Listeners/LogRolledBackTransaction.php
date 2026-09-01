<?php

namespace App\Listeners;

use App\Services\SecurityLogger;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Writes a line when a database transaction is discarded (#94, #98).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A LISTENER AND NOT A CALL IN EVERY SERVICE
 *
 * There are fifty `DB::transaction` calls across twenty-two services. Wrapping
 * each in a try/catch to log the failure would be fifty chances to forget, and
 * the one that got forgotten would be the one that mattered. The database
 * already announces every rollback; listening costs nothing and cannot be
 * skipped by a service written next year.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY IT IS WORTH LOGGING AT ALL
 *
 * A rollback is the only kind of failure that leaves NO evidence behind. The
 * sale did not save, the ledger did not move, the stock did not shift — by
 * design, because half a sale is worse than none. But that means the shop's
 * only record of "I rang that up and it vanished" is their memory, and support
 * has nothing to compare it against.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT DOES NOT KNOW
 *
 * The reason. The exception that caused the rollback is reported separately by
 * the exception handler — and both lines carry the same `ref`, so the pair joins
 * on one grep. Trying to capture the exception here as well would mean guessing
 * at which throwable was in flight, and guessing wrong on a log line is worse
 * than a line that admits what it does not know.
 */
class LogRolledBackTransaction
{
    public function __construct(protected SecurityLogger $logger) {}

    public function handle(TransactionRolledBack $event): void
    {
        /*
         | Off during tests. RefreshDatabase wraps every test in a transaction
         | and rolls it back at teardown, so leaving this on would write one
         | line per test and teach everyone to ignore the file. A test that
         | wants the listener turns it back on for itself.
         */
        if (! config('security.logging.rollbacks', true)) {
            return;
        }

        /*
         | Only the outermost rollback. Nested `DB::transaction` calls unwind to
         | a savepoint, and a savepoint rollback inside a transaction that then
         | commits successfully is not a failure — it is control flow.
         */
        if ($event->connection->transactionLevel() > 0) {
            return;
        }

        $this->logger->financialFailure('database transaction', null, [
            'connection' => $event->connectionName,
            'input' => $this->attempt(),
        ]);
    }

    /**
     * A sketch of what was being attempted, not the request body.
     *
     * The full input would carry customer names and payment detail into a log
     * file for every failure, and the shape is what actually answers "what were
     * they doing?" — a sale with three lines and a total, not the three lines.
     */
    protected function attempt(): array
    {
        $request = request();

        if ($request === null) {
            return [];
        }

        $input = $request->except(array_merge(
            (array) config('security.logging.redact', []),
            ['_method'],
        ));

        return array_map(
            fn ($value) => is_array($value) ? sprintf('[%d items]', count($value)) : $value,
            $input,
        );
    }
}
