<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Point of sale
    |--------------------------------------------------------------------------
    | Operator-level defaults for selling. #190 forbids hardcoding any of this,
    | and Phase 11 (#110) moves it into per-business settings — at which point
    | this file becomes the fallback, exactly as config/subscription.php and
    | config/inventory.php already are.
    */

    /*
    |--------------------------------------------------------------------------
    | Invoice numbering (#22)
    |--------------------------------------------------------------------------
    | Tokens, all optional:
    |
    |   {PREFIX}  the prefix below            {YYYY} 2026      {YY} 26
    |   {MM}      01–12                       {DD}   01–31
    |   {BRANCH}  the branch's own code       {SEQ}  the running number
    |
    | {SEQ} may carry a width — {SEQ:6} pads to 000123. The sequence restarts
    | according to `sequence_scope` below.
    |
    | The format is validated when a number is minted: whatever the tokens
    | produce, the result must still be unique per business, which the database
    | enforces regardless of what anyone types here.
    */
    'invoice' => [
        'format' => env('POS_INVOICE_FORMAT', '{PREFIX}-{YYYY}{MM}-{SEQ:5}'),
        'prefix' => env('POS_INVOICE_PREFIX', 'INV'),

        /*
         | Where the running number restarts:
         |   business — one continuous sequence for the whole tenant
         |   branch   — each shop counts its own
         |   monthly  — restarts on the 1st (only sensible with {YYYY}/{MM} in
         |              the format, or numbers would repeat)
         |
         | `business` is the default because a single unbroken sequence is what
         | most tax authorities expect to see.
         */
        'sequence_scope' => env('POS_INVOICE_SEQUENCE_SCOPE', 'business'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment methods (#17)
    |--------------------------------------------------------------------------
    | The methods a till may take. `credit` is special: it is not money changing
    | hands, it is the customer's account being charged, so it needs a customer
    | and it goes through the customer ledger rather than the drawer.
    |
    | Shops add their own here — JazzCash, EasyPaisa, a local wallet — without a
    | deploy, which is the whole point of it being config.
    */
    'payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('POS_PAYMENT_METHODS', 'cash,card,bank_transfer,qr,credit')),
    ))),

    // Which of those settle into the cash drawer, and therefore count towards
    // what a till should hold at close (#46).
    'cash_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('POS_CASH_METHODS', 'cash')),
    ))),

    // The one method that charges an account instead of taking money (#40).
    'credit_method' => env('POS_CREDIT_METHOD', 'credit'),

    /*
    | The shop's OWN payment QR — their wallet or bank code, shown to a customer
    | to scan (#57). Not the same thing as the receipt QR, which encodes a sale
    | that has already happened; this one is how the money arrives.
    |
    | An image rather than generated, because what a wallet encodes is decided
    | by the wallet: a bank hands the shop a code and the shop shows it. Trying
    | to generate one would mean guessing at a dozen national schemes and being
    | wrong about most of them.
    */
    'payment_qr_path' => env('POS_PAYMENT_QR_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    | Some currencies have no small coin left in circulation, so a till has to
    | round the payable total. 0 disables it; 0.5 rounds to the nearest fifty
    | paisa; 1 to the nearest rupee.
    */
    'cash_rounding' => (float) env('POS_CASH_ROUNDING', 0),

    /*
    |--------------------------------------------------------------------------
    | Selling rules
    |--------------------------------------------------------------------------
    */

    // May a sale go through with no customer attached? Yes by default — most
    // shop sales are to whoever is standing there (#146).
    'allow_walk_in' => (bool) env('POS_ALLOW_WALK_IN', true),

    // Must a till have an open cash session before it can sell (#139)? Off by
    // default: a shop that does not count its drawer should not be blocked from
    // trading by a feature it never opted into.
    'require_cash_session' => (bool) env('POS_REQUIRE_CASH_SESSION', false),

    // How long a held sale (#20) stays before it is considered abandoned.
    'hold_expiry_hours' => (int) env('POS_HOLD_EXPIRY_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Receipt (#23, #144)
    |--------------------------------------------------------------------------
    */
    'receipt' => [
        'width' => env('POS_RECEIPT_WIDTH', '80mm'),

        // Optional line above the shop name — a slogan, a branch note.
        'header' => env('POS_RECEIPT_HEADER'),

        'footer' => env('POS_RECEIPT_FOOTER', 'Thank you for shopping with us.'),

        // Printed under the shop name where the law asks for it (#57).
        'tax_number' => env('POS_RECEIPT_TAX_NUMBER'),

        'show_logo' => (bool) env('POS_RECEIPT_SHOW_LOGO', false),
        'show_tax_breakdown' => (bool) env('POS_RECEIPT_SHOW_TAX', true),

        /*
         | A QR carrying the invoice number, date and total (#154–#160). It is
         | not a link: a receipt has to stay checkable when the shop's internet
         | is down and when the customer is standing in a different country, so
         | the code carries the FACTS rather than a URL that has to resolve.
         */
        'show_qr' => (bool) env('POS_RECEIPT_SHOW_QR', false),

        // Opening the print dialog by itself is a courtesy on a till and an
        // ambush anywhere else, so it is off until a shop asks for it.
        'auto_print' => (bool) env('POS_RECEIPT_AUTO_PRINT', false),
    ],

];
