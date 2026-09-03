<?php

namespace App\Enums;

/**
 * Where a shop's plan request has got to (#82).
 *
 * ⚠️ These describe the CONVERSATION, not the subscription. Marking a request
 * "done" changes nothing about what the shop can do — the operator still has to
 * move them onto the plan and take the money. Wiring this to the subscription
 * would be a self-serve checkout, which this release deliberately does not have:
 * a button that silently upgraded a plan without a payment would be worse than
 * no button at all.
 */
enum PlanRequestStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Done => 'Done',
            self::Declined => 'Declined',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Done => 'badge-green',
            self::Declined => 'badge-slate',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
