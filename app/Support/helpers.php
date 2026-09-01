<?php

use App\Support\Format;

/*
| Short names for the four things every screen writes (#155–#157).
|
| These exist because a Blade file that says `\App\Support\Format::money($x)`
| forty times is unreadable, and an unreadable view is one people stop editing
| carefully. The class is still where the rules live; these are only the door.
*/

if (! function_exists('money')) {
    /** Money in the shop's format. Pass true for the currency symbol as well. */
    function money(float|int|string|null $amount, bool $withSymbol = false): string
    {
        return Format::money($amount, $withSymbol);
    }
}

if (! function_exists('qty')) {
    /** A quantity, with the trailing zeros taken off. */
    function qty(float|int|string|null $quantity): string
    {
        return Format::quantity($quantity);
    }
}

if (! function_exists('localDate')) {
    /** A stored UTC timestamp, written in the shop's date format and timezone. */
    function localDate(mixed $value): string
    {
        return Format::date($value);
    }
}

if (! function_exists('localDateTime')) {
    /** The same, with the time. */
    function localDateTime(mixed $value): string
    {
        return Format::dateTime($value);
    }
}
