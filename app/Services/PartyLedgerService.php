<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one place a party's balance is allowed to change (#41, #42, #183).
 *
 * Same shape as {@see InventoryService}, on purpose: the two problems are the
 * same problem. An append-only ledger is the truth, the party's `balance` column
 * is a cache maintained in the same locked transaction, and `recalculate()`
 * rebuilds one from the other — which is both the repair tool and the proof they
 * cannot legitimately disagree.
 *
 * WHAT ONE ENTRY DOES, atomically:
 *   1. Locks the party row, so two tills taking payment from the same customer
 *      queue instead of both reading the same starting balance.
 *   2. Turns the type and amount into a debit or a credit — the type decides,
 *      never the caller.
 *   3. Stamps the resulting balance onto the line, and updates the cache.
 *
 * Subclasses add only the party-specific vocabulary: which types are allowed on
 * this kind of account, and what the money is called when it moves.
 */
abstract class PartyLedgerService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected AuditService $audit,
    ) {}

    /** @return class-string<Model> */
    abstract protected function partyClass(): string;

    /** Does this type belong on this kind of account? */
    abstract protected function allowsType(LedgerEntryType $type): bool;

    /** "customer" / "supplier", for messages a human reads. */
    abstract protected function partyLabel(): string;

    // --------------------------------------------------------------- the write

    /**
     * Post one entry. The only way a balance ever changes.
     *
     * @param  array{
     *     type: LedgerEntryType|string,
     *     amount: float,
     *     entry_date?: string|\DateTimeInterface|null,
     *     description?: string|null,
     *     reference?: Model|null,
     *     reference_no?: string|null,
     *     payment_method?: string|null,
     *     branch_id?: int|null,
     *     user_id?: int|null,
     * }  $data
     *
     * `amount` is POSITIVE for directional types — the type decides whether it
     * debits or credits. For `opening` and `adjustment`, which can go either
     * way, the caller's sign is honoured.
     */
    public function post(Model $party, array $data): LedgerEntry
    {
        $type = $this->resolveType($data['type']);

        abort_unless(
            $this->allowsType($type),
            422,
            "A {$this->partyLabel()} account cannot carry a \"{$type->label()}\" entry.",
        );

        $this->assertOwnParty($party);

        $signed = $this->signedAmount($type, (float) $data['amount']);

        abort_if($signed === 0.0, 422, 'An entry of zero changes nothing.');

        return DB::transaction(function () use ($party, $type, $signed, $data): LedgerEntry {
            // Lock the account for the rest of this transaction. Without it two
            // payments posted at the same moment would both start from the same
            // balance and one would silently overwrite the other.
            $locked = $this->partyClass()::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();

            $before = (float) $locked->balance;
            $after = round($before + $signed, 2);

            $entry = new LedgerEntry([
                'business_id' => $locked->business_id,
                'party_type' => $locked->getMorphClass(),
                'party_id' => $locked->getKey(),
                'branch_id' => $data['branch_id'] ?? auth('web')->user()?->branch_id,
                'type' => $type,
                // Exactly one column carries the money; the other stays zero.
                'debit' => $signed > 0 ? abs($signed) : 0,
                'credit' => $signed < 0 ? abs($signed) : 0,
                'balance_after' => $after,
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'entry_date' => $this->resolveDate($data['entry_date'] ?? null),
                'user_id' => $data['user_id'] ?? auth('web')->id(),
                'created_at' => now(),
            ]);

            if (($reference = $data['reference'] ?? null) instanceof Model) {
                $entry->reference_type = $reference->getMorphClass();
                $entry->reference_id = $reference->getKey();
            }

            $entry->save();

            $locked->balance = $after;
            $locked->save();

            // Keep the caller's instance in step, so a controller that renders
            // the party straight afterwards shows the new figure.
            $party->balance = $after;
            $party->syncOriginalAttribute('balance');

            return $entry;
        });
    }

    /**
     * What the account started at (#152's idea, applied to money).
     *
     * Signed: positive means the account already owed when the shop began
     * keeping books here. Once only — a second opening balance is a correction,
     * and corrections are adjustments.
     */
    public function recordOpeningBalance(Model $party, float $amount, ?string $date = null): ?LedgerEntry
    {
        $exists = LedgerEntry::query()
            ->forParty($party)
            ->where('type', LedgerEntryType::Opening)
            ->exists();

        if ($exists || abs($amount) < 0.005) {
            return null;
        }

        return $this->post($party, [
            'type' => LedgerEntryType::Opening,
            'amount' => $amount,
            'entry_date' => $date,
            'description' => 'Opening balance',
        ]);
    }

    /**
     * A manual correction, with a reason.
     *
     * Signed, and the reason is required by the caller's validation rather than
     * being optional politeness: an unexplained change to what someone owes is
     * exactly the entry an auditor asks about first.
     */
    public function adjust(Model $party, float $amount, string $reason, ?string $date = null): LedgerEntry
    {
        $entry = $this->post($party, [
            'type' => LedgerEntryType::Adjustment,
            'amount' => $amount,
            'entry_date' => $date,
            'description' => $reason,
        ]);

        $this->audit->log(
            'ledger.adjusted',
            $entry,
            sprintf(
                '%s account for "%s" adjusted by %s.',
                ucfirst($this->partyLabel()),
                $party->name,
                number_format(abs($amount), 2),
            ),
            ['reason' => $reason, 'balance_after' => (float) $entry->balance_after],
        );

        return $entry;
    }

    // ------------------------------------------------------------- reporting

    /** The statement, oldest first so the running balance reads downwards. */
    public function statement(Model $party, ?string $from = null, ?string $to = null): Builder
    {
        return LedgerEntry::query()
            ->forParty($party)
            ->with(['user:id,name', 'branch:id,name'])
            ->between($from, $to)
            ->statementOrder();
    }

    /**
     * Totals for the period shown, so a statement can foot itself.
     *
     * `opening` is what the account stood at BEFORE the window — computed from
     * the entries outside it rather than assumed to be zero, which is what makes
     * a filtered statement add up.
     *
     * @return array{opening: float, debit: float, credit: float, closing: float, entries: int}
     */
    public function totals(Model $party, ?string $from = null, ?string $to = null): array
    {
        $window = LedgerEntry::query()->forParty($party)->between($from, $to);

        $debit = (float) (clone $window)->sum('debit');
        $credit = (float) (clone $window)->sum('credit');

        $opening = 0.0;

        if ($from !== null && $from !== '') {
            $before = LedgerEntry::query()
                ->forParty($party)
                ->whereDate('entry_date', '<', $from);

            $opening = round((float) (clone $before)->sum('debit') - (float) (clone $before)->sum('credit'), 2);
        }

        return [
            'opening' => $opening,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'closing' => round($opening + $debit - $credit, 2),
            'entries' => (clone $window)->count(),
        ];
    }

    /**
     * Rebuild the cached balance from the ledger.
     *
     * If this ever changes a number, the cache had drifted — and the ledger is
     * the truth, not the column.
     *
     * @return array{before: float, after: float, drifted: bool}
     */
    public function recalculate(Model $party): array
    {
        return DB::transaction(function () use ($party): array {
            $locked = $this->partyClass()::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();

            $before = (float) $locked->balance;

            $entries = LedgerEntry::query()->forParty($locked);
            $after = round((float) (clone $entries)->sum('debit') - (float) (clone $entries)->sum('credit'), 2);

            $locked->balance = $after;
            $locked->save();

            return [
                'before' => $before,
                'after' => $after,
                'drifted' => abs($before - $after) > 0.005,
            ];
        });
    }

    // ------------------------------------------------------------- internals

    protected function resolveType(LedgerEntryType|string $type): LedgerEntryType
    {
        if ($type instanceof LedgerEntryType) {
            return $type;
        }

        $resolved = LedgerEntryType::tryFrom($type);

        abort_if($resolved === null, 422, "Unknown ledger entry type [{$type}].");

        return $resolved;
    }

    /** Apply the type's direction, honouring the caller's sign for signed types. */
    protected function signedAmount(LedgerEntryType $type, float $amount): float
    {
        if ($type->isSigned()) {
            return round($amount, 2);
        }

        return round(abs($amount) * $type->direction(), 2);
    }

    protected function resolveDate(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date) && trim($date) !== '') {
            return Carbon::parse($date)->toDateString();
        }

        return now()->toDateString();
    }

    /**
     * The party must belong to the current tenant AND be the kind of party this
     * service handles — posting a supplier payment onto a customer would balance
     * perfectly and mean nothing.
     */
    protected function assertOwnParty(Model $party): void
    {
        $expected = $this->partyClass();

        abort_unless(
            $party instanceof $expected,
            422,
            "That is not a {$this->partyLabel()}.",
        );

        $businessId = $this->tenant->businessId();

        abort_if(
            $businessId !== null && (int) $party->business_id !== $businessId,
            404,
            'Not found.',
        );
    }
}
