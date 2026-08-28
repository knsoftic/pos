<?php

namespace App\Enums;

/**
 * What the app does once a subscription is past its grace period (#11).
 *
 * Chosen by the operator in config/subscription.php (Phase 11 moves it to the
 * settings screen). The point of having options rather than one hardcoded
 * behaviour: a lost-data-fear tenant is far more likely to renew if they can
 * still SEE their data, so read-only is the sensible default, but some
 * operators want a hard lock.
 */
enum ExpiryBehavior: string
{
    /** Nothing but the billing screen is reachable. */
    case Lock = 'lock';

    /** Data stays viewable; every write is refused. Default. */
    case ReadOnly = 'read_only';

    /** Only the POS/checkout is blocked; the back office keeps working. */
    case PosOff = 'pos_off';

    public function label(): string
    {
        return match ($this) {
            self::Lock => 'Lock everything',
            self::ReadOnly => 'Read-only access',
            self::PosOff => 'Disable POS only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Lock => 'Tenant can only reach the billing page until they renew.',
            self::ReadOnly => 'Tenant can view existing data but cannot create or change anything.',
            self::PosOff => 'Checkout is disabled; reports and back-office screens stay usable.',
        };
    }

    /** Resolve from config, falling back to the safe default if misconfigured. */
    public static function fromConfig(): self
    {
        return self::tryFrom((string) config('subscription.expiry_behavior')) ?? self::ReadOnly;
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
}
