<?php

namespace App\Enums;

/**
 * Where a purchase has got to (#36).
 *
 * THE DECISION THESE STATES ENCODE: ordering creates no liability and moves no
 * stock. A shop that has sent a purchase order owns nothing new and owes nothing
 * new — the goods are still at the supplier's. Both the shelf and the supplier's
 * account move on RECEIPT, and only for what actually arrived.
 *
 * That is why `Partial` exists as a state of its own rather than a flag: a
 * delivery that came up short is the normal case, not an error, and the shop
 * needs to see at a glance which orders still have goods outstanding.
 *
 *   Draft     — being written. Nothing posted; edit or delete freely.
 *   Ordered   — sent to the supplier. Still nothing posted.
 *   Partial   — some of it arrived. That part is on the shelf and on the bill.
 *   Received  — all of it arrived.
 *   Cancelled — called off. Anything already received stays received; only the
 *               outstanding part is abandoned.
 */
enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::Partial => 'Partly received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Not sent to the supplier yet. Nothing is on the shelf and nothing is owed.',
            self::Ordered => 'With the supplier. Still nothing on the shelf and nothing owed.',
            self::Partial => 'Some of it has arrived — that part is on the shelf and on the bill.',
            self::Received => 'All of it has arrived.',
            self::Cancelled => 'Called off. Anything already received stays received.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge-slate',
            self::Ordered => 'badge-brand',
            self::Partial => 'badge-amber',
            self::Received => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }

    /**
     * Only a draft may have its lines rewritten.
     *
     * Once an order has gone to the supplier, changing what was asked for
     * silently would leave the paperwork and the delivery disagreeing; once
     * anything has been received, the stock ledger has already spoken.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canBeOrdered(): bool
    {
        return $this === self::Draft;
    }

    /** Goods can still arrive against an order that is out or part-delivered. */
    public function canReceive(): bool
    {
        return in_array($this, [self::Ordered, self::Partial], true);
    }

    /**
     * A cancelled or fully received purchase is closed. A partly received one
     * can still be cancelled — that is how a shop abandons the rest of a
     * delivery that is never coming.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Ordered, self::Partial], true);
    }

    /** Has anything been posted to stock and to the supplier's account? */
    public function hasPosted(): bool
    {
        return in_array($this, [self::Partial, self::Received], true);
    }

    /** Only an untouched draft can actually be deleted (#104, #198). */
    public function canBeDeleted(): bool
    {
        return $this === self::Draft;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Ordered, self::Partial], true);
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
