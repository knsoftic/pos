<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's alert feed (#179).
 *
 * Everything here answers one question: what needs a human right now? A tenant
 * that has run out, a payment recorded but never confirmed, a queue full of
 * failed jobs. The super admin should not have to go looking for those.
 *
 * Two deliberate design decisions:
 *
 *   1. NOTHING IS CACHED. A stale entitlement is a security bug (#125); a stale
 *      ALERT is worse than useless — the operator fixes a problem and the badge
 *      keeps nagging, so they learn to ignore the badge. Alerts are recomputed
 *      per request and memoised only for the life of that request (the layout
 *      asks for the count, the page asks for the list).
 *
 *   2. STATE IS DERIVED, NEVER READ FROM `subscriptions.status` (#79). The whole
 *      point of one of these alerts is that the column can be WRONG; if this
 *      service trusted it, the one alert that reports drift could never fire.
 */
class SystemNotificationService
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** How many affected rows a single alert names before it says "+N more". */
    protected const MAX_ITEMS = 8;

    /** Sort weight — the operator sees the fires before the smoke. */
    protected const SEVERITY_ORDER = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];

    /** @var array<int, array<string, mixed>>|null per-request memo, not a cache */
    protected ?array $memo = null;

    public function __construct(
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Every alert worth showing, worst first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $alerts = array_values(array_filter([
            $this->failedJobsAlert(),
            ...$this->subscriptionAlerts(),
            $this->noSubscriptionAlert(),
            $this->pendingPaymentsAlert(),
            $this->suspendedAlert(),
        ]));

        usort($alerts, function (array $a, array $b): int {
            return [self::SEVERITY_ORDER[$a['severity']], -$a['count']]
                <=> [self::SEVERITY_ORDER[$b['severity']], -$b['count']];
        });

        return $this->memo = $alerts;
    }

    /** How many separate things need attention — this is the bell badge. */
    public function count(): int
    {
        return count($this->alerts());
    }

    /** Total affected rows across every alert, for the page subtitle. */
    public function affectedCount(): int
    {
        return array_sum(array_column($this->alerts(), 'count'));
    }

    public function hasAlerts(): bool
    {
        return $this->alerts() !== [];
    }

    /** True when at least one alert is costing somebody money right now. */
    public function hasCritical(): bool
    {
        foreach ($this->alerts() as $alert) {
            if ($alert['severity'] === self::DANGER) {
                return true;
            }
        }

        return false;
    }

    /** The first N alerts — what the topbar dropdown has room for. */
    public function preview(int $limit = 5): array
    {
        return array_slice($this->alerts(), 0, $limit);
    }

    /** Drop the memo. Only needed when one request fixes a problem and re-reads. */
    public function flush(): void
    {
        $this->memo = null;
    }

    // ------------------------------------------------------------ subscriptions

    /**
     * One pass over the live subscriptions, classified into MUTUALLY EXCLUSIVE
     * buckets by derived state. Exclusive matters: a tenant three days from
     * expiry that is also in grace is one problem, not two, and counting it
     * twice would make the badge lie.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subscriptionAlerts(): array
    {
        $expired = [];
        $grace = [];
        $trialEnding = [];
        $expiring = [];
        $drifted = [];

        $window = $this->warningWindow();
        $trialWindow = $this->trialWarningWindow();

        foreach ($this->currentSubscriptions() as $subscription) {
            $business = $subscription->business;

            if ($business === null) {
                continue; // orphan row (business deleted) — nothing to act on
            }

            $status = $subscription->effectiveStatus();

            // Orthogonal to the buckets below: this is about the DATABASE being
            // out of step, not about the tenant. It can co-occur with any state.
            if ($subscription->status !== $status) {
                $drifted[] = $this->item(
                    $business,
                    sprintf('column says %s, dates say %s', $subscription->status->value, $status->value),
                );
            }

            if ($status === SubscriptionStatus::Cancelled) {
                continue; // the tenant asked to stop; not a problem to chase
            }

            if ($status === SubscriptionStatus::Expired) {
                $expired[] = $this->item($business, $this->endedAgo($subscription));

                continue;
            }

            if ($subscription->isInGrace()) {
                $days = $subscription->graceDaysRemaining();
                $grace[] = $this->item($business, $days === 0
                    ? 'grace ends today'
                    : sprintf('%d grace day%s left', $days, $days === 1 ? '' : 's'));

                continue;
            }

            if ($subscription->isOnTrial()) {
                $trialDays = $this->daysUntil($subscription->trial_ends_at);

                if ($trialDays !== null && $trialDays <= $trialWindow) {
                    $trialEnding[] = $this->item($business, $trialDays === 0
                        ? 'trial ends today'
                        : sprintf('trial ends in %d day%s', $trialDays, $trialDays === 1 ? '' : 's'));
                }

                continue; // a trial is never also "expiring" — it has not been paid for yet
            }

            $remaining = $subscription->daysRemaining();

            if ($remaining !== null && $remaining <= $window) {
                $expiring[] = $this->item($business, $remaining === 0
                    ? 'ends today'
                    : sprintf('%d day%s left', $remaining, $remaining === 1 ? '' : 's'));
            }
        }

        return array_filter([
            $this->alert(
                key: 'expired',
                severity: self::DANGER,
                icon: 'ban',
                title: 'Expired subscriptions',
                items: $expired,
                message: fn (int $n) => $n === 1
                    ? '1 business has run past its grace period and can no longer work normally.'
                    : sprintf('%d businesses have run past their grace period and can no longer work normally.', $n),
                url: route('admin.businesses.index', ['subscription' => SubscriptionStatus::Expired->value]),
                action: 'Review expired businesses',
            ),
            $this->alert(
                key: 'grace',
                severity: self::WARNING,
                icon: 'clock',
                title: 'In grace period',
                items: $grace,
                message: fn (int $n) => sprintf(
                    '%s past the paid period but still working — access stops when grace runs out.',
                    $n === 1 ? '1 business is' : $n.' businesses are',
                ),
                url: route('admin.subscriptions.index'),
                action: 'Open subscriptions',
            ),
            $this->alert(
                key: 'expiring',
                severity: self::WARNING,
                icon: 'calendar',
                title: 'Expiring soon',
                items: $expiring,
                message: fn (int $n) => sprintf(
                    '%s within the next %d days.',
                    $n === 1 ? '1 subscription ends' : $n.' subscriptions end',
                    $this->warningWindow(),
                ),
                url: route('admin.subscriptions.index', ['expiring' => $this->warningWindow()]),
                action: 'Open subscriptions',
            ),
            $this->alert(
                key: 'trials_ending',
                severity: self::WARNING,
                icon: 'zap',
                title: 'Trials ending',
                items: $trialEnding,
                message: fn (int $n) => sprintf(
                    '%s about to finish — the moment to convert them.',
                    $n === 1 ? '1 trial is' : $n.' trials are',
                ),
                url: route('admin.subscriptions.index', ['status' => SubscriptionStatus::Trial->value]),
                action: 'Open trials',
            ),
            $this->alert(
                key: 'status_drift',
                severity: self::INFO,
                icon: 'refresh',
                title: 'Subscription statuses out of date',
                items: $drifted,
                message: fn (int $n) => sprintf(
                    '%s stored status no longer matches its dates. Access is unaffected (it is always derived), but reports and filters read the column.',
                    $n === 1 ? '1 subscription\'s' : $n.' subscriptions\'',
                ),
                hint: 'php artisan subscriptions:reconcile',
            ),
        ]);
    }

    /** Live rows only, with the tenant attached. Explicitly untenanted (#82). */
    protected function currentSubscriptions()
    {
        return Subscription::query()
            ->allTenants()
            ->current()
            ->with(['business:id,name,slug,status', 'plan:id,name'])
            ->get();
    }

    /**
     * Active tenants that cannot do anything at all, because nobody ever gave
     * them a plan. Usually a half-finished manual signup.
     */
    protected function noSubscriptionAlert(): ?array
    {
        $subscribed = Subscription::query()->allTenants()->current()->pluck('business_id')->all();

        $orphans = Business::query()
            ->where('status', Business::STATUS_ACTIVE)
            ->when($subscribed !== [], fn ($q) => $q->whereNotIn('id', $subscribed))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return $this->alert(
            key: 'no_subscription',
            severity: self::DANGER,
            icon: 'alert',
            title: 'No subscription',
            items: $orphans->map(fn (Business $business) => $this->item($business, 'never subscribed'))->all(),
            message: fn (int $n) => sprintf(
                '%s active but has no plan at all, so every screen is behind the paywall.',
                $n === 1 ? '1 business is' : $n.' businesses are',
            ),
            url: route('admin.businesses.index', ['subscription' => 'none']),
            action: 'Assign a plan',
        );
    }

    /** Money the operator wrote down but never confirmed (#84). */
    protected function pendingPaymentsAlert(): ?array
    {
        $pending = SubscriptionPayment::query()
            ->allTenants()
            ->where('status', PaymentStatus::Pending);

        $count = (clone $pending)->count();

        if ($count === 0) {
            return null;
        }

        return $this->alert(
            key: 'pending_payments',
            severity: self::WARNING,
            icon: 'credit-card',
            title: 'Payments awaiting confirmation',
            items: [],
            count: $count,
            message: fn (int $n) => sprintf(
                '%s recorded but not yet marked paid (%s).',
                $n === 1 ? '1 payment is' : $n.' payments are',
                number_format((float) (clone $pending)->sum('amount'), 2),
            ),
            url: route('admin.subscriptions.index'),
            action: 'Open subscriptions',
        );
    }

    /** Suspended tenants — deliberate, but a forgotten suspension is lost revenue. */
    protected function suspendedAlert(): ?array
    {
        $suspended = Business::query()
            ->where('status', Business::STATUS_SUSPENDED)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return $this->alert(
            key: 'suspended',
            severity: self::INFO,
            icon: 'lock',
            title: 'Suspended businesses',
            items: $suspended->map(fn (Business $business) => $this->item($business, 'suspended'))->all(),
            message: fn (int $n) => sprintf(
                '%s suspended and cannot log in.',
                $n === 1 ? '1 business is' : $n.' businesses are',
            ),
            url: route('admin.businesses.index', ['status' => Business::STATUS_SUSPENDED]),
            action: 'Review suspensions',
        );
    }

    /**
     * Queue failures. Guarded by hasTable() because the queue tables are
     * optional — on a sync-driver install there is nothing to report, and
     * missing infrastructure must not blow up the dashboard.
     */
    protected function failedJobsAlert(): ?array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return null;
        }

        $latest = DB::table('failed_jobs')->orderByDesc('id')->limit(self::MAX_ITEMS)->get(['queue', 'failed_at']);

        return $this->alert(
            key: 'failed_jobs',
            severity: self::DANGER,
            icon: 'alert',
            title: 'Failed background jobs',
            items: $latest->map(fn ($job) => [
                'label' => 'queue: '.($job->queue ?: 'default'),
                'meta' => (string) $job->failed_at,
                'url' => null,
            ])->all(),
            count: $count,
            message: fn (int $n) => sprintf(
                '%s failed. Invoices, emails or reminders may never have gone out.',
                $n === 1 ? '1 background job has' : $n.' background jobs have',
            ),
            hint: 'php artisan queue:retry all',
        );
    }

    // ---------------------------------------------------------------- plumbing

    /**
     * Assemble one alert, or null when there is nothing to say. Every alert has
     * the same shape so the view never has to check which key it is looking at.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  callable(int): string  $message
     * @return array<string, mixed>|null
     */
    protected function alert(
        string $key,
        string $severity,
        string $icon,
        string $title,
        array $items,
        callable $message,
        ?int $count = null,
        ?string $url = null,
        ?string $action = null,
        ?string $hint = null,
    ): ?array {
        $count ??= count($items);

        if ($count === 0) {
            return null;
        }

        return [
            'key' => $key,
            'severity' => $severity,
            'icon' => $icon,
            'title' => $title,
            'message' => $message($count),
            'count' => $count,
            'url' => $url,
            'action' => $url === null ? null : ($action ?? 'View'),
            'hint' => $hint,
            'items' => array_slice($items, 0, self::MAX_ITEMS),
            // "+N more" only makes sense when some are actually listed; an alert
            // that reports a bare count (payments, failed jobs) shows none.
            'more' => $items === [] ? 0 : max(0, $count - min(count($items), self::MAX_ITEMS)),
        ];
    }

    /** @return array<string, mixed> */
    protected function item(Business $business, string $meta): array
    {
        return [
            'label' => $business->name,
            'meta' => $meta,
            'url' => route('admin.businesses.show', $business->id),
        ];
    }

    /** The widest window the operator configured — no hardcoded 7 (#190). */
    protected function warningWindow(): int
    {
        $days = array_filter(array_map('intval', (array) config('subscription.warning_days')));

        return $days === [] ? 7 : max($days);
    }

    /**
     * Trials get the TIGHTEST window instead of the widest. A trial is short by
     * nature, so warning 30 days out would flag every trial that exists and the
     * alert would carry no information.
     */
    protected function trialWarningWindow(): int
    {
        $days = array_filter(array_map('intval', (array) config('subscription.warning_days')));

        return $days === [] ? 3 : min($days);
    }

    protected function daysUntil(mixed $date): ?int
    {
        if ($date === null) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false));
    }

    protected function endedAgo(Subscription $subscription): string
    {
        $ended = $subscription->graceEndsAt() ?? $subscription->ends_at;

        if ($ended === null) {
            return 'expired';
        }

        $days = (int) $ended->copy()->startOfDay()->diffInDays(now()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'expired today',
            $days === 1 => 'expired yesterday',
            default => sprintf('expired %d days ago', $days),
        };
    }
}
