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
