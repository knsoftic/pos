<?php

namespace App\Enums;

/**
 * Where a sale has got to (#20, #21).
 *
 * Deliberately few states. A sale is not a workflow: money and goods change
 * hands in one motion at a till, so the only states that earn their place are
 * the two either side of that motion, plus the one that undoes it.
 *
 *   Held      — parked mid-transaction (#20). Nothing has been posted: no
 *               stock has moved and nobody owes anything. A customer went back
 *               for the milk they forgot.
 *   Completed — done. Stock is off the shelf, the money is recorded, and any
 *               credit is on the customer's account.
 *   Voided    — cancelled after the fact. The record STAYS and the postings are
 *               reversed (#133, #198); a sale is never deleted.
 *
 * There is no "draft": an unfinished sale at a till is a held sale, and calling
 * it something else would just give the POS two words for one thing.
 */
enum SaleStatus: string
{
    case Held = 'held';
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Completed => 'Completed',
            self::Voided => 'Voided',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Held => 'Parked at the till. Nothing has been posted yet.',
            self::Completed => 'Sold. Stock is off the shelf and the money is recorded.',
            self::Voided => 'Cancelled after the fact — the postings were reversed and the record kept.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Held => 'badge-amber',
            self::Completed => 'badge-green',
            self::Voided => 'badge-red',
        };
    }

    /** Has this sale moved stock and money? */
    public function hasPosted(): bool
    {
        return $this === self::Completed;
    }

    /** Only a held sale may still have its lines rewritten. */
    public function isEditable(): bool
    {
        return $this === self::Held;
    }

    public function canBeVoided(): bool
    {
        return $this === self::Completed;
    }

    /**
     * A held sale can be abandoned outright — it posted nothing, so there is
     * nothing to keep. A completed one is voided instead (#198).
     */
    public function canBeDeleted(): bool
    {
        return $this === self::Held;
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
