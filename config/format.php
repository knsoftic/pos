<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency (#58)
    |--------------------------------------------------------------------------
    | How money is written, everywhere. These are DEFAULTS — a business overrides
    | them in Settings → Currency & formats, and the override is overlaid onto
    | this config at the start of every request (see SettingsService::apply), so
    | anything reading `config('format.*')` sees the shop's answer without
    | knowing a settings table exists.
    |
    | ⚠️ This is about DISPLAY only. Every amount is stored as a decimal in the
    | database and calculated in full precision; changing the symbol or the
    | decimal places never changes a stored figure. A shop that switches from
    | two decimals to zero is changing how its receipts read, not what it has
    | taken.
    */

    'currency_code' => env('FORMAT_CURRENCY_CODE', 'PKR'),

    'currency_symbol' => env('FORMAT_CURRENCY_SYMBOL', 'Rs'),

    // Before: Rs 1,250.00 — after: 1,250.00 Rs. Both are normal somewhere.
    'currency_position' => env('FORMAT_CURRENCY_POSITION', 'before'),

    /*
    | Two for most currencies. Zero where the smallest coin has been withdrawn
    | from circulation and a decimal place would print a figure nobody can pay.
    */
    'decimals' => (int) env('FORMAT_DECIMALS', 2),

    'thousands_separator' => env('FORMAT_THOUSANDS_SEPARATOR', ','),

    'decimal_separator' => env('FORMAT_DECIMAL_SEPARATOR', '.'),

    /*
    |--------------------------------------------------------------------------
    | Dates and times (#155, #156)
    |--------------------------------------------------------------------------
    | PHP date() formats. `d M Y` is the default because 01/09/2026 means two
    | different days depending on which side of the Atlantic the reader is on,
    | and a POS is read by whoever is standing at the till.
    |
    | ⚠️ Timestamps are STORED IN UTC (#153, #154) and converted to the
    | business's timezone only when they are shown. A shop that moves timezone
    | does not rewrite its history.
    */

    'date' => env('FORMAT_DATE', 'd M Y'),

    'time' => env('FORMAT_TIME', 'H:i'),

];
