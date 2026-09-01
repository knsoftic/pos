<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How this installation behaves (#110, #160)
    |--------------------------------------------------------------------------
    | Operator-level switches, as distinct from the per-shop settings in
    | `config/pos.php` and friends. These are DEFAULTS; the super admin
    | overrides them in /admin/settings and the override is overlaid onto this
    | config at the start of every request (see PlatformSettingsService).
    */

    /*
    | Whether a stranger may create an account from the public site (#108, #109).
    | Off is the safe default: a fresh installation should not be open to the
    | internet before anybody has looked at it.
    */
    'registration_open' => (bool) env('PLATFORM_REGISTRATION_OPEN', false),

    /*
    | The plan a self-signup starts on. Null means "the cheapest public plan",
    | resolved at the time rather than pinned, so retiring a plan cannot leave
    | registration pointing at nothing.
    */
    'trial_plan_id' => env('PLATFORM_TRIAL_PLAN_ID'),

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode (#160)
    |--------------------------------------------------------------------------
    | ⚠️ This is NOT `php artisan down`. Laravel's maintenance mode takes the
    | whole application off the air including /admin, which locks the operator
    | out of the very screen that would turn it back on. This one closes the
    | TENANT workspace and the public site and deliberately leaves /admin open.
    |
    | It is also a database flag rather than a file, because a deployment across
    | several web servers has to switch it everywhere at once.
    */
    'maintenance' => (bool) env('PLATFORM_MAINTENANCE', false),

    'maintenance_message' => env(
        'PLATFORM_MAINTENANCE_MESSAGE',
        'We are carrying out scheduled maintenance and will be back shortly.',
    ),

    /*
    | An escape hatch for the deploy itself: anyone holding this token reaches
    | the app during maintenance via ?maintenance_token=…, so the operator can
    | check that the release works before letting shops back in. Blank disables
    | it entirely.
    */
    'maintenance_token' => env('PLATFORM_MAINTENANCE_TOKEN', ''),

];
