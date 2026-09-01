<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Billing cycles a plan can be sold on (#175).
 *
 * The set of cycles is a fixed system concept (the code has to know how to add
 * a month vs. a year), while the PRICE for each cycle is per-plan data in the
 * `plan_prices` table — so nothing about pricing is hardcoded. #190
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case Lifetime = 'lifetime';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::HalfYearly => 'Half-Yearly',
            self::Yearly => 'Yearly',
            self::Lifetime => 'Lifetime',
            self::Custom => 'Custom',
        };
    }

    /** Short suffix for price display, e.g. "$49 /mo". */
    public function suffix(): string
    {
        return match ($this) {
            self::Monthly => '/mo',
            self::Quarterly => '/3 mo',
            self::HalfYearly => '/6 mo',
            self::Yearly => '/yr',
            self::Lifetime => 'one-time',
            self::Custom => 'custom',
        };
    }

    /**
     * When a subscription bought on this cycle expires.
     *
     * Calendar-aware on purpose: a monthly cycle started on the 31st should
     * land on the last day of the next month, not drift by counting 30 days.
     *
     * @param  int|null  $customDays  Required for {@see self::Custom}.
     * @return CarbonInterface|null NULL means "never expires" (lifetime). #174
     */
    public function expiryFrom(CarbonInterface $start, ?int $customDays = null): ?CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Quarterly => $start->copy()->addMonthsNoOverflow(3),
            self::HalfYearly => $start->copy()->addMonthsNoOverflow(6),
            self::Yearly => $start->copy()->addYearNoOverflow(),
            self::Lifetime => null,
            self::Custom => $start->copy()->addDays(max(1, (int) $customDays)),
        };
    }

    /** Lifetime never renews; everything else does. #174 */
    public function isRecurring(): bool
    {
        return $this !== self::Lifetime;
    }

    public function requiresCustomDays(): bool
    {
        return $this === self::Custom;
    }

    /** Approximate month count — used to show "save X%" comparisons. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
            self::Lifetime, self::Custom => null,
        };
    }

    /** @return array<string, string> value => label, for <select> options. */
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
