<?php

namespace App\Enums;

/**
 * Why a stock figure changed (#29, #30).
 *
 * Every movement is signed: incoming types add, outgoing types subtract, and
 * `adjustment` and `stock_take` can do either — a correction is not inherently
 * a gain or a loss. {@see direction()} is the single place that decides, so no
 * caller ever has to remember which way a purchase return goes.
 *
 * These are system concepts, not tenant data: each one is written by a specific
 * code path (a sale, a purchase, a transfer), so the code has to know the set.
 */
enum StockMovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case StockTake = 'stock_take';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::Purchase => 'Purchase',
            self::PurchaseReturn => 'Return to supplier',
            self::Sale => 'Sale',
            self::SaleReturn => 'Customer return',
            self::Adjustment => 'Adjustment',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::StockTake => 'Stock take',
        };
    }

    /**
     * +1 adds, -1 subtracts, 0 means "the caller's sign decides".
     *
     * Adjustments and stock takes are the only two that can go either way: a
     * count that finds less stock than the system thought is as normal as one
     * that finds more.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::Purchase, self::SaleReturn, self::TransferIn => 1,
            self::Sale, self::PurchaseReturn, self::TransferOut => -1,
            self::Adjustment, self::StockTake => 0,
        };
    }

    public function isIncoming(): bool
    {
        return $this->direction() > 0;
    }

    public function isOutgoing(): bool
    {
        return $this->direction() < 0;
    }

    /** Can this type move stock in either direction? */
    public function isSigned(): bool
    {
        return $this->direction() === 0;
    }

    /**
     * Does this movement bring a cost with it?
     *
     * Only incoming stock can change what the business paid; a sale consumes
     * value at the cost already on the books, it does not set it.
     */
    public function carriesCost(): bool
    {
        return in_array($this, [self::Opening, self::Purchase, self::TransferIn, self::Adjustment, self::StockTake], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Opening => 'badge-slate',
            self::Purchase, self::SaleReturn, self::TransferIn => 'badge-green',
            self::Sale, self::PurchaseReturn, self::TransferOut => 'badge-red',
            self::Adjustment, self::StockTake => 'badge-amber',
        };
    }

    /** @return array<string, string> value => label, for filters. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
