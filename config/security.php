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
