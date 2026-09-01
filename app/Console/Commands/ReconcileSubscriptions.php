<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Bring `subscriptions.status` back in line with the dates (#11, #179).
 *
 * IMPORTANT: this command does not grant or revoke anything. Access is always
 * derived from the dates (#79) — {@see Subscription::effectiveStatus()} —
 * precisely so that a missed cron run cannot let an expired tenant keep selling.
 * What the column is for is reporting and list filters, and those go stale
 * without this. Run it daily.
 */
class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Rewrite stale subscription status columns from the derived state';

    public function handle(SubscriptionService $subscriptions): int
    {
        $changed = $subscriptions->reconcileStatuses();

        if ($changed === 0) {
            $this->info('Nothing to do — every subscription status already matches its dates.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d subscription %s brought back in line.',
            $changed,
            $changed === 1 ? 'status' : 'statuses',
        ));

        return self::SUCCESS;
    }
}
