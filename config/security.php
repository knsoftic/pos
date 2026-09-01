<?php

/*
|--------------------------------------------------------------------------
| Security Settings
|--------------------------------------------------------------------------
| Central, env-driven security knobs. Nothing security-related should be
| hardcoded in controllers or requests — read it from here. #100 / #190
|
| Session timeout itself lives in config/session.php (`SESSION_LIFETIME`,
| `SESSION_EXPIRE_ON_CLOSE`) — see the `session` block below for the
| app-level idle handling that builds on it. #161
*/

return [

    /*
    | Password policy (#63). Applied globally via Password::defaults() in
    | AppServiceProvider, so every place that validates a password — login
    | reset, employee creation, profile change — uses the same rules.
    */
    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 8),
        'require_mixed_case' => (bool) env('PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_numbers' => (bool) env('PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => (bool) env('PASSWORD_REQUIRE_SYMBOLS', false),
        // Checks the password against known breach lists (needs outbound HTTP).
        'require_uncompromised' => (bool) env('PASSWORD_REQUIRE_UNCOMPROMISED', false),
    ],

    /*
    | Brute-force throttling on authentication endpoints (#65).
    */
    'throttle' => [
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 1),
        // Per email+IP: stops one account being hammered.
        'password_reset_max_attempts' => (int) env('PASSWORD_RESET_MAX_ATTEMPTS', 3),
        // Per IP regardless of address: stops sweeping many emails to find which
        // ones are registered. Must be higher than the per-account limit.
        'password_reset_ip_max_attempts' => (int) env('PASSWORD_RESET_IP_MAX_ATTEMPTS', 15),
        'password_reset_decay_minutes' => (int) env('PASSWORD_RESET_DECAY_MINUTES', 10),

        /*
        | Sign-up (#109). An unauthenticated endpoint that creates a business, a
        | user and a subscription is the most expensive thing a stranger can
        | reach, and left open it fills the plans table with junk tenants that
        | then have to be told apart from real ones by hand.
        */
        'register_max_attempts' => (int) env('REGISTER_MAX_ATTEMPTS', 5),
        'register_decay_minutes' => (int) env('REGISTER_DECAY_MINUTES', 60),

        /*
        | Global search (#75) fires on every keystroke, so the ceiling has to sit
        | well above a fast typist and still stop a runaway loop from turning one
        | browser tab into a load test.
        */
        'search_per_minute' => (int) env('SEARCH_PER_MINUTE', 120),

        /*
        | Report exports (#33). Each one is a full aggregate plus a file build;
        | a handful a minute is generous for a person and ruinous for a script.
        */
        'export_per_minute' => (int) env('EXPORT_PER_MINUTE', 20),

        /*
        | Posting a sale (#14). Deliberately loose — a supermarket lane can post
        | one every few seconds and a till that refuses a sale is worse than
        | almost anything this limit prevents. The real defence against a double
        | submit is the per-cart idempotency key and its unique index; this is
        | only a ceiling on something pathological.
        */
        'sale_per_minute' => (int) env('SALE_PER_MINUTE', 120),
    ],

    /*
    | Response headers (#100). Every one of these is a browser-side defence, so
    | they are only worth anything when they reach EVERY response — hence a
    | middleware on the whole `web` group rather than per route.
    |
    | ⚠️ NO Content-Security-Policy here, and that is a decision rather than an
    | omission. This app renders Alpine expressions as inline attributes and
    | ships a handful of inline <script> blocks, so any CSP it could pass today
    | would need `unsafe-inline` — which permits exactly the injection a CSP
    | exists to stop. A header that looks like protection and is not is worse
    | than none, because it stops anyone from asking again. A real CSP needs
    | nonces threaded through the layouts; that is its own piece of work.
    */
    'headers' => [
        // Stops a browser guessing a response is HTML when we said it was not —
        // the trick that turns an uploaded "image" into a script.
        'content_type_options' => env('SECURITY_CONTENT_TYPE_OPTIONS', 'nosniff'),

        // Clickjacking: nobody frames a till. An invisible POS in an iframe over
        // a decoy page is a sale posted by a click the cashier meant elsewhere.
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'SAMEORIGIN'),

        // A URL in this app can carry an invoice number or a customer id; full
        // referrers would hand those to every outbound link.
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        // The app asks for none of these, so it should be unable to ask.
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=(), payment=()'),

        /*
        | HSTS. Off by default and ON only over HTTPS in production, because a
        | max-age served from a development box pins that hostname to HTTPS in
        | the developer's browser for months — including `localhost`.
        */
        'hsts_enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'hsts_include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    /*
    | Logging (#94). Two channels beyond the application log, because these two
    | questions get asked long after the fact and by different people:
    |
    |   security  — "who tried what, and was it stopped?"   (operator)
    |   financial — "the sale did not save. what happened?" (shop, then support)
    |
    | A rolled-back financial transaction leaves NO row anywhere by definition —
    | that is the whole point of the rollback — so if it is not written to a log
    | at the moment it fails, the event never existed.
    */
    'logging' => [
        'security_channel' => env('SECURITY_LOG_CHANNEL', 'security'),
        'financial_channel' => env('FINANCIAL_LOG_CHANNEL', 'financial'),

        /*
        | Log every discarded database transaction. Turned OFF for the test
        | suite (phpunit.xml), where RefreshDatabase rolls one back per test and
        | leaving it on would write seven hundred lines of noise — which is how
        | a log file stops being read.
        */
        'rollbacks' => (bool) env('LOG_ROLLBACKS', true),

        /*
        | Never written to a log, an audit row or an error report, at any level.
        | Matched case-insensitively against the KEY, and against nested keys, so
        | `payment.card_number` is redacted as readily as `card_number`.
        */
        'redact' => [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'token',
            '_token',
            'api_token',
            'remember_token',
            'secret',
            'authorization',
            'cookie',
            'card_number',
            'cvv',
            'cvc',
            'pin',
        ],
    ],

    /*
    | Session / inactivity handling (#161). `lifetime_minutes` mirrors
    | config('session.lifetime') and is the hard timeout. `warn_before_minutes`
    | is how long before expiry the UI should warn the user (0 = never).
    */
    'session' => [
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),
        'warn_before_minutes' => (int) env('SESSION_WARN_BEFORE_MINUTES', 5),
    ],

];
