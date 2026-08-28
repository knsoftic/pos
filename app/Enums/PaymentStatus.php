<?php

namespace App\Enums;

/**
 * State of a recorded subscription payment (#82).
 *
 * These are SYSTEM states, not operator-configurable business data, so an enum
 * is the right home — unlike the payment METHOD (bank transfer, JazzCash, …),
 * which is deployment config. #190
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Pending => 'Pending',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Paid => 'badge-green',
            self::Pending => 'badge-amber',
            self::Failed => 'badge-red',
            self::Refunded => 'badge-slate',
        };
    }

    /** Only settled money counts towards revenue totals. */
    public function countsAsRevenue(): bool
    {
        return $this === self::Paid;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            []
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
