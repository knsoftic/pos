<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discounts (#60, #141)
    |--------------------------------------------------------------------------
    | What a shop lets its people give away.
    |
    | THREE THINGS DECIDE WHETHER A DISCOUNT IS ALLOWED, and they are checked in
    | this order:
    |
    |   1. The PLAN — `sales.discounts` has to be included at all.
    |   2. The SHOP — these settings: whether line and invoice discounts are
    |      offered, and the ceiling nobody may pass.
    |   3. The PERSON — `users.max_discount_percent`, which can only ever be
    |      tighter than the shop's ceiling, never looser.
    |
    | A cap belongs to the person, not to the sale (#141): the same 20% is fine
    | from a manager and not fine from a new cashier, and it is checked in the
    | form request because that is the only place that knows who is asking.
    */

    'allow_line_discount' => (bool) env('SALES_ALLOW_LINE_DISCOUNT', true),

    'allow_invoice_discount' => (bool) env('SALES_ALLOW_INVOICE_DISCOUNT', true),

    /*
    | The shop-wide ceiling. 100 means "no shop-wide limit" — individual people
    | may still be capped lower. It is the fallback for anyone whose own limit
    | has not been set, so raising it does not accidentally hand every cashier
    | an unlimited discount.
    */
    'max_discount_percent' => (float) env('SALES_MAX_DISCOUNT_PERCENT', 100),

];
