<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Support\FeatureRegistry;

/**
 * A customer's account (#41, #183).
 *
 * The balance is money RECEIVABLE: positive means the customer owes the
 * business. Sales debit it, payments credit it — the arithmetic lives in
 * {@see PartyLedgerService}, and this class adds only what is true of customers
 * specifically: which entry types belong here, and the credit limit (#40).
 */
class CustomerLedgerService extends PartyLedgerService
{
    protected function partyClass(): string
    {
        return Customer::class;
    }

    protected function allowsType(LedgerEntryType $type): bool
    {
        return $type->appliesToCustomers();
    }

    protected function partyLabel(): string
    {
        return 'customer';
    }

    /**
     * Money taken from a customer against what they owe (#41).
     *
     * Deliberately NOT capped at the outstanding balance: a customer who pays
     * more than they owe leaves the account in credit, which is a real thing
     * shops deal with (a deposit, a rounded-up payment) and must not be silently
     * clipped.
     */
    public function recordPayment(Customer $customer, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A payment must be more than zero.');

        $entry = $this->post($customer, [
            'type' => LedgerEntryType::PaymentReceived,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Payment received',
            'reference_no' => $options['reference_no'] ?? null,
            'payment_method' => $options['payment_method'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);

        $this->audit->log(
            'customer.payment_received',
            $entry,
            sprintf('Received %s from "%s".', number_format($amount, 2), $customer->name),
            [
                'amount' => $amount,
                'method' => $entry->payment_method,
                'balance_after' => (float) $entry->balance_after,
            ],
        );

        return $entry;
    }

    /**
     * Charge a credit sale to the account.
     *
     * The gate lives here rather than in the POS, so that every route to a
     * credit sale — the till, an API, an imported order — meets the same limit
     * (#40). Blocked customers never pass, whatever their limit says (#105).
     */
    public function chargeSale(Customer $customer, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A sale must be more than zero.');

        // Selling on credit is a PLAN capability as well as a per-customer one
        // (#40). Checked here rather than only at the till, so an import or an
        // API call cannot put a tenant on account terms their plan excludes.
        $this->features->authorize(FeatureRegistry::SALES_CREDIT_SALES);

        abort_if(
            $customer->isBlocked(),
            422,
            "\"{$customer->name}\" is blocked and cannot buy on account.",
        );

        abort_unless(
            $customer->canTakeCredit($amount),
            422,
            $this->overLimitMessage($customer, $amount),
        );

        return $this->post($customer, [
            'type' => LedgerEntryType::Sale,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Credit sale',
            'reference' => $options['reference'] ?? null,
            'reference_no' => $options['reference_no'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);
    }

    /** Goods coming back reduce what the customer owes. */
    public function recordReturn(Customer $customer, float $amount, array $options = []): LedgerEntry
    {
        abort_if($amount <= 0, 422, 'A return must be more than zero.');

        return $this->post($customer, [
            'type' => LedgerEntryType::SaleReturn,
            'amount' => $amount,
            'entry_date' => $options['entry_date'] ?? null,
            'description' => $options['description'] ?? 'Sale return',
            'reference' => $options['reference'] ?? null,
            'reference_no' => $options['reference_no'] ?? null,
            'branch_id' => $options['branch_id'] ?? null,
        ]);
    }

    /**
     * What the profile screen shows (#39): what they have bought, what they have
     * paid, and what is left.
     *
     * Read from the ledger rather than from sales, so the figures are the same
     * ones the statement foots to — a summary that disagrees with the statement
     * below it is worse than no summary.
     *
     * @return array{purchased: float, returned: float, paid: float, balance: float, entries: int, last_activity: ?string}
     */
    public function summary(Customer $customer): array
    {
        $entries = LedgerEntry::query()->forParty($customer);

        $purchased = (float) (clone $entries)->where('type', LedgerEntryType::Sale)->sum('debit');
        $returned = (float) (clone $entries)->where('type', LedgerEntryType::SaleReturn)->sum('credit');
        $paid = (float) (clone $entries)->where('type', LedgerEntryType::PaymentReceived)->sum('credit');

        return [
            'purchased' => round($purchased, 2),
            'returned' => round($returned, 2),
            'paid' => round($paid, 2),
            'balance' => round((float) $customer->balance, 2),
            'entries' => (clone $entries)->count(),
            'last_activity' => (clone $entries)->max('entry_date'),
        ];
    }

    protected function overLimitMessage(Customer $customer, float $amount): string
    {
        $available = $customer->availableCredit() ?? 0;

        if ($customer->creditLimit() === 0.0) {
            return "\"{$customer->name}\" is a cash-only account — set a credit limit first.";
        }

        return sprintf(
            '%s would go past their credit limit: %s available, %s needed.',
            $customer->name,
            number_format($available, 2),
            number_format($amount, 2),
        );
    }
}
