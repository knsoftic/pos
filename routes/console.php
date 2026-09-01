<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
| Requires ONE cron entry on the server:
|
|   * * * * * cd /path/to/pos && php artisan schedule:run >> /dev/null 2>&1
|
| Nothing here is load-bearing for access control. Subscription access is
| derived from dates on every request (#79), so a server whose cron is
| misconfigured cannot accidentally keep an expired tenant selling — the
| stored status column simply goes stale, which the operator's alert feed
| reports as its own item (#179).
*/

// Keep `subscriptions.status` in step with the dates, just after midnight
// (#11). withoutOverlapping() in case a large tenant list runs long.
Schedule::command('subscriptions:reconcile')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Held sales nobody came back for (#169).
|
| Hourly rather than nightly because the window is a shop setting and can be
| short — a shop that holds orders for two hours should not wait until midnight
| for the till to clear itself.
|
| Safe to automate precisely because a held sale has posted NOTHING: no stock
| moved, no money recorded, no invoice number spent (#118). This throws away a
| basket, not a transaction.
*/
Schedule::command('pos:expire-holds')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
| The nightly proof that every cached balance still matches its ledger (#170).
|
| This system's whole shape is "the ledger is the truth, the column is a cache
| of it" — and that is only worth something if somebody checks. It REPORTS and
| does not repair: quietly correcting a discrepancy would destroy the evidence
| of whatever caused it, and the cause matters more than the symptom.
|
| Runs in the background so a large tenant list cannot delay the rest of the
| schedule behind it.
*/
Schedule::command('pos:check-integrity')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| Housekeeping (#170). Weekly, because nothing here is urgent and a prune that
| runs nightly is a prune nobody notices has gone wrong.
|
| ⚠️ Audit entries past their retention window and orphaned uploads ONLY.
| Nothing financial is ever pruned (#133, #198).
*/
Schedule::command('pos:prune')
    ->weeklyOn(7, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
