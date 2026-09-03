<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * How money, quantities, dates and times are written (#58, #153–#157).
 *
 * ================= ONE PLACE, BECAUSE IT IS READ EVERYWHERE =================
 * A receipt, a report and a screen that formatted money three different ways
 * would be three different products to the person reading them. Every rule
 * lives here and reads from `config('format.*')`, which the settings overlay
 * has already filled with the shop's own answers.
 *
 * ================= TIME IS STORED IN UTC AND SHOWN LOCAL (#153, #154) =======
 * Every timestamp in the database is UTC. It is converted to the business's
 * timezone at the moment it is displayed, and nowhere else. That is what lets a
 * shop change timezone — or a chain run branches in two of them — without a
 * single stored row being rewritten, and it is why `local()` takes the timezone
 * from the tenant rather than from the server.
 *
 * ⚠️ Never format a date by calling `->format()` directly on a model attribute
 * in a view. That prints UTC, which is the right answer roughly nowhere.
 */
class Format
{
    /**
     * Money, the way this shop writes it.
     *
     * `$withSymbol` is off by default because most of the system shows money in
     * a column under a heading that already says what it is; repeating "Rs" on
     * every one of forty rows is noise. The receipt and the totals turn it on.
     */
    public static function money(float|int|string|null $amount, bool $withSymbol = false): string
    {
        $number = number_format(
            (float) ($amount ?? 0),
            (int) config('format.decimals', 2),
            (string) config('format.decimal_separator', '.'),
            (string) config('format.thousands_separator', ','),
        );

        if (! $withSymbol) {
            return $number;
        }

        $symbol = (string) config('format.currency_symbol', '');

        return config('format.currency_position', 'before') === 'after'
            ? trim($number.' '.$symbol)
            : trim($symbol.' '.$number);
    }

    /**
     * Money in the OPERATOR's currency — what KN Softic charges the shop.
     *
     * ⚠️ NOT {@see self::money()}. That one writes what the shop charges ITS
     * customers, and the two are different settings on purpose: a shop can sell
     * in rupees while being billed in dollars. Using the wrong one on a billing
     * screen puts the till's symbol on the operator's invoice.
     *
     * `$currency` is the ISO code SNAPSHOTTED onto the row when it was sold. It
     * is passed rather than looked up because history must not be relabelled: a
     * payment of USD 39 stays USD 39 after the operator switches to rupees, and
     * printing "Rs 39" would be wrong by a factor of about three hundred. So —
     *
     *   matches today's currency → the symbol,    "Rs 1,000"
     *   an older currency        → its ISO code,  "USD 39"
     *   blank or missing         → today's symbol
     *
     * ⚠️ That last case is why this exists. Two models each compared the
     * snapshot against config and, finding an empty string, printed it followed
     * by a space — so the page showed a bare number with a gap where the money
     * marker belonged. The figure was right, nothing errored, and the currency
     * had simply vanished. Old and imported rows really do arrive like that.
     */
    public static function billingMoney(float|int|string|null $amount, ?string $currency = null): string
    {
        $today = (string) config('subscription.currency', '');
        $currency = trim((string) $currency);

        $marker = ($currency === '' || $currency === $today)
            ? (string) config('subscription.currency_symbol', '')
            : $currency.' ';

        return $marker.number_format(
            (float) ($amount ?? 0),
            (int) config('subscription.currency_decimals', 2),
        );
    }

    /**
     * A quantity, with the trailing zeros taken off.
     *
     * Stock is stored to four decimals so that 0.35 kg of something is exact,
     * but "3.0000" on a receipt reads like a rounding error rather than three.
     */
    public static function quantity(float|int|string|null $quantity): string
    {
        $number = number_format(
            (float) ($quantity ?? 0),
            4,
            (string) config('format.decimal_separator', '.'),
            (string) config('format.thousands_separator', ','),
        );

        $decimal = (string) config('format.decimal_separator', '.');

        return str_contains($number, $decimal)
            ? rtrim(rtrim($number, '0'), $decimal)
            : $number;
    }

    /** A percentage, at one decimal — enough to be useful, few enough to scan. */
    public static function percent(float|int|string|null $value, int $decimals = 1): string
    {
        return number_format((float) ($value ?? 0), $decimals).'%';
    }

    public static function date(mixed $value): string
    {
        $date = self::local($value);

        return $date === null ? '' : $date->format((string) config('format.date', 'd M Y'));
    }

    public static function time(mixed $value): string
    {
        $date = self::local($value);

        return $date === null ? '' : $date->format((string) config('format.time', 'H:i'));
    }

    public static function dateTime(mixed $value): string
    {
        $date = self::local($value);

        return $date === null
            ? ''
            : $date->format(config('format.date', 'd M Y').', '.config('format.time', 'H:i'));
    }

    /**
     * A stored (UTC) instant, in the shop's own timezone.
     *
     * Falls back to the app timezone when there is no tenant — a console
     * command or the super-admin panel, neither of which belongs to a shop.
     */
    public static function local(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $date = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);

        return $date->setTimezone(self::timezone());
    }

    public static function timezone(): string
    {
        $business = app(TenantContext::class)->business();

        return $business?->timezone ?: (string) config('app.timezone', 'UTC');
    }

    /** "PKR" — for an export header or an API payload, where a symbol is ambiguous. */
    public static function currencyCode(): string
    {
        return (string) config('format.currency_code', '');
    }

    public static function currencySymbol(): string
    {
        return (string) config('format.currency_symbol', '');
    }
}
