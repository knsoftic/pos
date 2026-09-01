<?php

namespace App\Enums;

/**
 * Why a party's balance changed (#41, #42).
 *
 * THE ONE RULE THAT MAKES BOTH LEDGERS WORK:
 *
 *   A DEBIT increases what the account owes. A CREDIT reduces it.
 *
 * That is mechanically identical for customers and suppliers; what differs is
 * what "owes" MEANS, and that difference belongs to the party, not to the
 * arithmetic:
 *
 *   Customer — the balance is money RECEIVABLE. A sale on credit debits them
 *              (they owe more); the cash they hand over credits them.
 *   Supplier — the balance is money PAYABLE. A purchase on credit debits the
 *              account (we owe more); paying their bill credits it.
 *
 * So a positive balance always reads the same way on screen: "this account is
 * owed money" for a supplier, "this account owes money" for a customer. One
 * arithmetic, two headings — rather than two sign conventions that will one day
 * be mixed up.
 *
 * `direction()` is the single place that decides, exactly as
 * {@see StockMovementType::direction()} does for stock, so no caller ever has to
 * remember which way a return goes.
 */
enum LedgerEntryType: string
{
    case Opening = 'opening';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case PaymentReceived = 'payment_received';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case PaymentMade = 'payment_made';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::PaymentReceived => 'Payment received',
            self::Purchase => 'Purchase',
            self::PurchaseReturn => 'Purchase return',
            self::PaymentMade => 'Payment made',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * +1 debits (the account owes more), −1 credits (it owes less), 0 means the
     * caller's sign decides.
     *
     * Only an adjustment and an opening balance can go either way: a shop
     * starting up may be owed money or owe it, and a correction is not
     * inherently one or the other.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Sale, self::Purchase => 1,
            self::SaleReturn, self::PaymentReceived, self::PurchaseReturn, self::PaymentMade => -1,
            self::Opening, self::Adjustment => 0,
        };
    }

    public function isDebit(): bool
    {
        return $this->direction() > 0;
    }

    public function isCredit(): bool
    {
        return $this->direction() < 0;
    }

    /** Can this type move the balance either way? */
    public function isSigned(): bool
    {
        return $this->direction() === 0;
    }

    /** Which party ledgers this type may appear on. */
    public function appliesToCustomers(): bool
    {
        return in_array($this, [
            self::Opening, self::Sale, self::SaleReturn, self::PaymentReceived, self::Adjustment,
        ], true);
    }

    public function appliesToSuppliers(): bool
    {
        return in_array($this, [
            self::Opening, self::Purchase, self::PurchaseReturn, self::PaymentMade, self::Adjustment,
        ], true);
    }

    /** Is this money actually changing hands, rather than an invoice being raised? */
    public function isSettlement(): bool
    {
        return in_array($this, [self::PaymentReceived, self::PaymentMade], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Opening => 'badge-slate',
            self::Sale, self::Purchase => 'badge-brand',
            self::PaymentReceived, self::PaymentMade => 'badge-green',
            self::SaleReturn, self::PurchaseReturn => 'badge-amber',
            self::Adjustment => 'badge-red',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
