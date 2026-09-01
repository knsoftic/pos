<?php

namespace App\Enums;

/**
 * Whether a till's drawer is open for business (#46, #139).
 *
 * Two states, because there are only two: someone has counted the float in and
 * is trading, or they have counted it out and gone home. What makes the pair
 * useful is that a session BRACKETS a period of trading, so "what should be in
 * this drawer" is answerable — opening float, plus the cash taken, less the cash
 * paid out — and the difference against what was actually counted is the number
 * a shop actually cares about.
 */
enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'badge-green',
            self::Closed => 'badge-slate',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
