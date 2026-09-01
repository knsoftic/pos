<?php

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Supplier;
use App\Services\PartyLedgerService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared by {@see Customer} and {@see Supplier}: an
 * account with a running balance behind it (#41, #42).
 *
 * The balance column is a CACHE over the ledger, maintained in the same
 * transaction as the entry that changed it. Nothing outside
 * {@see PartyLedgerService} may write it, which is why `balance`
 * is not fillable on either model.
 *
 * WHAT A POSITIVE BALANCE MEANS depends on the party, and each model says so in
 * its own words — see `owesUs()` / `weOwe()`. The arithmetic never changes:
 * debit up, credit down.
 */
trait HasLedger
{
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'party');
    }

    /** Has this account settled to nothing? */
    public function isSettled(): bool
    {
        return abs((float) $this->balance) < 0.005;
    }

    /**
     * The balance as an absolute figure, for screens that put the direction in
     * a heading rather than a minus sign.
     */
    public function absoluteBalance(): float
    {
        return abs(round((float) $this->balance, 2));
    }

    /**
     * Which way the account currently sits, in words the screen can print
     * without knowing which kind of party it is holding.
     */
    public function balanceDirection(): string
    {
        $balance = (float) $this->balance;

        return match (true) {
            abs($balance) < 0.005 => 'settled',
            $balance > 0 => 'owing',
            default => 'in_credit',
        };
    }

    public function balanceBadgeClass(): string
    {
        return match ($this->balanceDirection()) {
            'settled' => 'badge-slate',
            'owing' => 'badge-amber',
            default => 'badge-green',
        };
    }
}
