<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand identity — KN Softic
    |--------------------------------------------------------------------------
    | Every fact about who publishes this software lives here, exactly once, and
    | is read at runtime (#190). Nothing in a Blade file, an email or a receipt
    | should ever contain the company name as a literal string — a rebrand, a
    | new support address or a white-label deployment must be a config change,
    | not a search-and-replace across 200 files.
    |
    | WHOSE NAME APPEARS WHERE — the rule this whole file exists to serve:
    |
    |   /login, /admin, the public site, emails  → KN SOFTIC. These are the
    |       product's own surfaces; the person looking at them is dealing with
    |       the software vendor.
    |
    |   /app (a tenant's workspace)              → THE BUSINESS. A shopkeeper's
    |       workspace belongs to the shop, and their staff should see the shop's
    |       name, not their supplier's. KN Softic appears there only as a quiet
    |       "Powered by" line in the footer.
    |
    | Getting that backwards is the classic white-label mistake: it makes every
    | customer feel like they are working inside someone else's product.
    */

    'name' => env('BRAND_NAME', 'KN Softic'),

    // Used where a formal entity is required — invoices, terms, legal footers.
    'legal_name' => env('BRAND_LEGAL_NAME', 'KN Softic'),

    // The product, as distinct from the company that makes it.
    'product' => env('BRAND_PRODUCT', 'KN Softic POS'),

    'tagline' => env('BRAND_TAGLINE', 'Cloud POS & Sales Management'),

    // One sentence, for the login screen and meta description.
    'description' => env(
        'BRAND_DESCRIPTION',
        'Multi-branch point of sale, inventory and sales management — built for shops that need to move fast.',
    ),

    /*
    |--------------------------------------------------------------------------
    | Contact
    |--------------------------------------------------------------------------
    | Shown on the login footer, in system emails and on receipts. Any of these
    | may be left empty and the UI simply omits that line rather than rendering
    | a blank label.
    */

    'website' => env('BRAND_WEBSITE', 'https://knsoftic.com'),
    'website_label' => env('BRAND_WEBSITE_LABEL', 'knsoftic.com'),
    'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@knsoftic.com'),
    'sales_email' => env('BRAND_SALES_EMAIL', 'sales@knsoftic.com'),
    'support_phone' => env('BRAND_SUPPORT_PHONE', ''),
    'address' => env('BRAND_ADDRESS', ''),

    /*
    |--------------------------------------------------------------------------
    | Copyright
    |--------------------------------------------------------------------------
    | The footer renders "© 2026 KN Softic" and grows to "© 2026–2031" on its
    | own, so nobody has to remember to update a year in January.
    */

    'copyright_since' => (int) env('BRAND_COPYRIGHT_SINCE', 2026),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    | Surfaced in the operator console and in the tenant footer, so a support
    | conversation can start with "which version are you on?" and get an answer.
    */

    'version' => env('BRAND_VERSION', '1.0.0-beta'),

];
