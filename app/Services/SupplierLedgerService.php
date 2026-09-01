<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Supplier;

/**
 * A supplier's account (#42, #183).
 *
 * The mirror of {@see CustomerLedgerService}: the balance is money PAYABLE, so
 * positive means the business owes this supplier. Purchases debit the account,
 * payments credit it. Same arithmetic, opposite heading — see
 * {@see LedgerEntryType} for why that is one rule and not two.
 */
class SupplierLedgerService extends PartyLedgerService
{
    protected function partyClass(): string
    {
        return Supplier::class;
    }

    protected function allowsType(LedgerEntryType $type): bool
    {
        return $type->appliesToSuppliers();
    }

    protected function partyLabel(): string
    {
        return 'supplier';
    }

    /** A bill from the supplier — the business now owes more. */
    public function recordPurchase(Supplier $supplier, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A purchase must be more than zero.');

        abort_if(
            $supplier->isBlocked(),
            422,
            "\"{$supplier->name}\" is blocked, so nothing can be bought from them.",
        );

        return $this->post($supplier, [
            'type' => LedgerEntryType::Purchase,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Purchase',
            'reference' => $options['reference'] ?? null,
            'reference_no' => $options['reference_no'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);
    }

    /**
     * Money paid out to the supplier (#42).
     *
     * Not capped at the outstanding balance, for the same reason a customer
     * payment is not: an advance against next month's delivery is ordinary
     * trade, and it leaves the account the other way round.
     */
    public function recordPayment(Supplier $supplier, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A payment must be more than zero.');

        $entry = $this->post($supplier, [
            'type' => LedgerEntryType::PaymentMade,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Payment made',
            'reference_no' => $options['reference_no'] ?? null,
            'payment_method' => $options['payment_method'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);

        $this->audit->log(
            'supplier.payment_made',
            $entry,
            sprintf('Paid %s to "%s".', number_format($amount, 2), $supplier->name),
            [
                'amount' => $amount,
                'method' => $entry->payment_method,
                'balance_after' => (float) $entry->balance_after,
            ],
        );

        return $entry;
    }

    /** Goods sent back reduce what the business owes. */
    public function recordReturn(Supplier $supplier, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A return must be more than zero.');

        return $this->post($supplier, [
            'type' => LedgerEntryType::PurchaseReturn,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Purchase return',
            'reference' => $options['reference'] ?? null,
            'reference_no' => $options['reference_no'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);
    }

    /**
     * The supplier profile's figures (#38): bought, returned, paid, outstanding.
     *
     * From the ledger, so they foot to the statement underneath them.
     *
     * @return array{purchased: float, returned: float, paid: float, balance: float, entries: int, last_activity: ?string}
     */
    public function summary(Supplier $supplier): array
    {
        $entries = LedgerEntry::query()->forParty($supplier);

        return [
            'purchased' => round((float) (clone $entries)->where('type', LedgerEntryType::Purchase)->sum('debit'), 2),
            'returned' => round((float) (clone $entries)->where('type', LedgerEntryType::PurchaseReturn)->sum('credit'), 2),
            'paid' => round((float) (clone $entries)->where('type', LedgerEntryType::PaymentMade)->sum('credit'), 2),
            'balance' => round((float) $supplier->balance, 2),
            'entries' => (clone $entries)->count(),
            'last_activity' => (clone $entries)->max('entry_date'),
        ];
    }
}
