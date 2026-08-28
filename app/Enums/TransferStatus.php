<?php

namespace App\Enums;

/**
 * Where a stock transfer has got to (#32).
 *
 * The states exist because a transfer is not instantaneous: goods leave one
 * shop before they arrive at another, and for that window they belong to
 * neither shelf. Collapsing this to a single "move stock" button would make
 * every van journey invisible and every shortfall unattributable.
 *
 *   Draft     — being written. Nothing has moved; edit or delete freely.
 *   Sent      — goods have left the source. Stock is OFF the source shelf and
 *               not yet on the destination's: it is in transit.
 *   Received  — the destination counted what arrived. Stock is on their shelf,
 *               and any shortfall is now a recorded fact rather than a mystery.
 *   Cancelled — called off. From draft that costs nothing; after sending, the
 *               goods are put back where they came from.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'In transit',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Not sent yet — nothing has left the shelf.',
            self::Sent => 'On its way. The stock has left the source branch and is not yet counted in.',
            self::Received => 'Counted in at the destination.',
            self::Cancelled => 'Called off.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge-slate',
            self::Sent => 'badge-amber',
            self::Received => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }

    /** Only a draft may have its lines rewritten — after that the ledger has spoken. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canBeSent(): bool
    {
        return $this === self::Draft;
    }

    public function canBeReceived(): bool
    {
        return $this === self::Sent;
    }

    /** A received transfer is history; correcting it is an adjustment, not a cancel. */
    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    /** Is the stock currently sitting between two shelves? */
    public function isInTransit(): bool
    {
        return $this === self::Sent;
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
