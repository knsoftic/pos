<?php

namespace App\Enums;

/**
 * Lifecycle of a subscription row.
 *
 * IMPORTANT — stored vs. effective:
 *   `subscriptions.status` stores the operator's INTENT (trial / active /
 *   cancelled). Whether a subscription has actually run out is a function of
 *   dates, so it is always DERIVED via {@see \App\Models\Subscription::effectiveStatus()}
 *   rather than trusted from the column. A stale row can therefore never let an
 *   expired tenant keep working, which is the whole point of enforcement (#79).
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Tailwind badge class from the shared design system (resources/css/app.css). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Trial => 'badge-brand',
            self::Active => 'badge-green',
            self::Expired => 'badge-red',
            self::Cancelled => 'badge-slate',
        };
    }

    /**
     * May the tenant use the app at all?
     *
     * Grace handling is deliberately NOT here — a subscription inside its grace
     * window still reports Active (with a warning surfaced separately), and only
     * flips to Expired once grace is used up. See Subscription::effectiveStatus().
     */
    public function grantsAccess(): bool
    {
        return $this === self::Trial || $this === self::Active;
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
