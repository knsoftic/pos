<?php

/*
|--------------------------------------------------------------------------
| Subscription / Billing Settings
|--------------------------------------------------------------------------
| Operator-level defaults for the SaaS billing layer. #190 forbids hardcoding
| plans, prices, currencies, payment methods, features and limits — so:
|
|   - PLANS, PRICES, FEATURES and LIMITS live entirely in the DATABASE
|     (plans / plan_prices / features / limits + their pivots). Nothing here.
|   - This file only holds *system defaults* that apply when a plan does not
|     specify its own value (trial length, grace period, expiry behaviour…)
|     plus the operator's own billing currency and accepted payment methods.
|
| Phase 11 (#110 Super Admin settings) moves these into a DB-backed settings
| screen; until then they are env-driven so a deploy can change them without
| touching code.
*/

use App\Enums\ExpiryBehavior;

return [

    /*
    | Currency the OPERATOR bills tenants in. Note this is separate from each
    | business's own POS currency (#58, Phase 11) — a tenant can sell in PKR
    | while being billed in USD. Snapshotted onto every subscription/payment
    | row so historical records never change when this default changes.
    |
    | The shipped plan catalogue is priced in rupees, so the default matches it.
    | Changing this RELABELS the numbers, it does not convert them -- set it, and
    | the plan prices, together or not at all.
    */
    'currency' => env('SUBSCRIPTION_CURRENCY', 'PKR'),
    'currency_symbol' => env('SUBSCRIPTION_CURRENCY_SYMBOL', 'Rs'),
    // Nobody quotes a subscription in paisa.
    'currency_decimals' => (int) env('SUBSCRIPTION_CURRENCY_DECIMALS', 0),

    /*
    | Fallback trial length, used when a plan has no trial_days of its own (#81).
    */
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),

    /*
    | Grace period after expiry (#127). Resolution order:
    |   subscription.grace_days → plan.grace_days → this value.
    | During grace the tenant is still let in, but warned loudly.
    */
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 3),

    /*
    | What happens once the grace period is over (#11):
    |   lock      → nothing but the billing screen is reachable
    |   read_only → data can be viewed, but no writes (safest default)
    |   pos_off   → only the POS/checkout is blocked, back office keeps working
    */
    'expiry_behavior' => env('SUBSCRIPTION_EXPIRY_BEHAVIOR', ExpiryBehavior::ReadOnly->value),

    /*
    | Days before expiry at which the tenant is warned (#11). Descending.
    */
    'warning_days' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('SUBSCRIPTION_WARNING_DAYS', '7,3,1'))
    ))),

    /*
    | Payment methods the operator accepts for subscription invoices (#82).
    | Comma-separated so a deployment can offer local rails (JazzCash, EasyPaisa)
    | without a code change. Keys are stored; labels are humanised for display.
    */
    'payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SUBSCRIPTION_PAYMENT_METHODS',
            'bank_transfer,card,cash,jazzcash,easypaisa,other'
        ))
    ))),

    /*
    | Cache TTL (seconds) for a business's resolved feature/limit map. Feature
    | checks run on nearly every request, so they are cached and invalidated
    | whenever a plan or an override changes (#96 / #168).
    */
    'cache_ttl' => (int) env('SUBSCRIPTION_CACHE_TTL', 3600),

];
