<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inventory policy
    |--------------------------------------------------------------------------
    | Operator-level defaults for how stock behaves. #190 forbids hardcoding
    | these anywhere in the code — everything here is read at runtime.
    |
    | Phase 11 (#110 Super Admin settings) moves these into a DB-backed
    | per-business settings table; the config then becomes the fallback, exactly
    | as with config/subscription.php. Nothing outside this file will need to
    | change when that happens.
    */

    /*
    |--------------------------------------------------------------------------
    | Negative stock (#142)
    |--------------------------------------------------------------------------
    | May a sale take a shelf below zero?
    |
    | DEFAULT NO, and that default matters: a POS that lets stock go negative is
    | quietly telling the shop its stock figures are fiction. Some businesses do
    | need it — a bakery selling what is still in the oven, a shop whose
    | purchase paperwork always lags — so it is a setting rather than a rule.
    */
    'allow_negative_stock' => (bool) env('INVENTORY_ALLOW_NEGATIVE_STOCK', false),

    /*
    |--------------------------------------------------------------------------
    | Low-stock alerts (#33)
    |--------------------------------------------------------------------------
    | The fallback threshold for products that do not set their own
    | `alert_quantity`. Null means "only warn about products that ask to be
    | warned about", which is the quieter and more useful default: a blanket
    | threshold across a whole catalogue produces noise nobody reads.
    */
    'default_alert_quantity' => env('INVENTORY_DEFAULT_ALERT_QUANTITY') !== null
        ? (float) env('INVENTORY_DEFAULT_ALERT_QUANTITY')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Expiry warning window (#34)
    |--------------------------------------------------------------------------
    | How many days ahead an approaching expiry date starts being reported.
    */
    'expiry_warning_days' => (int) env('INVENTORY_EXPIRY_WARNING_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Stock valuation
    |--------------------------------------------------------------------------
    | How the value of what is on hand is calculated. `average` keeps a weighted
    | average cost per shelf, which survives stock takes and corrections without
    | layers to unwind. FIFO arrives with batch tracking (#34) for the shops that
    | need it.
    */
    'valuation_method' => env('INVENTORY_VALUATION_METHOD', 'average'),

];
