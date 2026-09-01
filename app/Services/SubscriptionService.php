<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\ExpiryBehavior;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place subscriptions are created or changed (#82, #176, #186).
 *
 * FOUR RULES THIS CLASS EXISTS TO ENFORCE
 * ---------------------------------------
 * 1. EVERY mutation runs inside a DB transaction. Superseding the old row and
 *    inserting the new one is a single atomic step — a crash in between would
 *    otherwise leave a tenant with two "current" subscriptions or none. #131
 * 2. EVERY mutation is audited before the transaction closes, so a rolled-back
 *    change leaves no misleading trail. #177
 * 3. EVERY mutation flushes the feature and limit caches. A stale entitlement
 *    after a downgrade is a security bug, not a display bug.
 * 4. Prices are SNAPSHOT onto the subscription row. Re-pricing a plan tomorrow
 *    must not rewrite what a tenant agreed to today. #198
 *
 * It also doubles as the read-side facade for the 3-layer access check (#187):
 * layer 1 (subscription + feature) lives here, layer 2 (user permission) arrives
 * in Phase 3, layer 3 (tenant) is the global scope.
 *
 * Mutations run under {@see TenantContext::runFor()} so `business_id` is stamped
 * by the tenant trait rather than passed around by hand — the operator console
 * has no ambient tenant, and this keeps the write path identical to a tenant's.
 */
class SubscriptionService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    // ------------------------------------------------------------------ reads

    /** The live subscription for a business (or the active tenant). */
    public function current(int|Business|null $business = null): ?Subscription
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return null;
        }

        return Subscription::query()
            ->forBusiness($businessId)
            ->whereNull('superseded_at')
            ->with('plan')
            ->latest('id')
            ->first();
    }

    /** Full history, newest first (#176). */
    public function history(int|Business|null $business = null)
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return collect();
        }

        return Subscription::query()
            ->forBusiness($businessId)
            ->with('plan')
            ->orderByDesc('id')
            ->get();
    }

    /** May this business use the app at all right now? */
    public function isActive(int|Business|null $business = null): bool
    {
        return (bool) $this->current($business)?->grantsAccess();
    }

    public function isOnTrial(int|Business|null $business = null): bool
    {
        return (bool) $this->current($business)?->isOnTrial();
    }

    public function isInGrace(int|Business|null $business = null): bool
    {
        return (bool) $this->current($business)?->isInGrace();
    }

    public function daysRemaining(int|Business|null $business = null): ?int
    {
        return $this->current($business)?->daysRemaining();
    }

    public function plan(int|Business|null $business = null): ?Plan
    {
        return $this->current($business)?->plan;
    }

    /** What happens to a tenant once the subscription lapses (#79, #190). */
    public function expiryBehavior(): ExpiryBehavior
    {
        return ExpiryBehavior::fromConfig();
    }

    // ------------------------------------------- delegations (one entry point)

    public function hasFeature(string $code, int|Business|null $business = null): bool
    {
        return $this->features->enabled($code, $business);
    }

    public function getLimit(string $code, int|Business|null $business = null): ?int
    {
        return $this->limits->limit($code, $business);
    }

    public function usage(string $code, int|Business|null $business = null): int
    {
        return $this->limits->usage($code, $business);
    }

    public function canCreate(string $code, int $quantity = 1, int|Business|null $business = null): bool
    {
        return $this->limits->canCreate($code, $quantity, $business);
    }

    /** @return array<string, array<string, mixed>> */
    public function meters(?array $codes = null, int|Business|null $business = null): array
    {
        return $this->limits->meters($codes, $business);
    }

    // ------------------------------------------------------------- mutations

    /**
     * Put a business on a free trial of a plan (#25).
     *
     * `ends_at` is set to the trial end as well, so expiry, grace and the
     * "N days left" banner all work off one column for trials and paid periods
     * alike instead of each caller special-casing trials.
     */
    public function startTrial(Business $business, Plan $plan, ?int $days = null, ?string $notes = null): Subscription
    {
        $days = $days ?? $plan->trialDays() ?? (int) config('subscription.trial_days');
        $days = max(1, (int) $days);

        return $this->write($business, function () use ($business, $plan, $days, $notes): Subscription {
            $this->supersedeCurrent($business);

            $start = now();
            $end = $start->copy()->addDays($days);

            $subscription = $this->makeSubscription($business, [
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::Monthly,
                'price' => 0,
                'currency' => config('subscription.currency'),
                'status' => SubscriptionStatus::Trial,
                'starts_at' => $start,
                'ends_at' => $end,
                'trial_ends_at' => $end,
                'notes' => $notes,
            ]);

            $this->audit->log(
                'subscription.trial_started',
                $subscription,
                "Started a {$days}-day trial of {$plan->name}.",
                ['plan' => $plan->slug, 'trial_days' => $days, 'ends_at' => $end->toDateTimeString()],
            );

            return $subscription;
        });
    }

    /**
     * Put a business on a paid plan, replacing whatever it had.
     *
     * @param  array{starts_at?: CarbonInterface|null, price?: float|null, custom_days?: int|null, grace_days?: int|null, notes?: string|null, trial_days?: int|null}  $options
     */
    public function assign(Business $business, Plan $plan, BillingCycle $cycle, array $options = []): Subscription
    {
        return $this->write($business, function () use ($business, $plan, $cycle, $options): Subscription {
            $this->supersedeCurrent($business);

            $start = $options['starts_at'] ?? now();
            $customDays = $options['custom_days'] ?? $plan->price($cycle)?->custom_days;
            $price = $options['price'] ?? $this->priceFor($plan, $cycle);

            $trialDays = isset($options['trial_days']) ? max(0, (int) $options['trial_days']) : 0;
            $trialEnd = $trialDays > 0 ? $start->copy()->addDays($trialDays) : null;

            $end = $cycle->expiryFrom($start, $customDays);

            // A trial cannot outlive the paid period it precedes.
            if ($trialEnd !== null && $end !== null && $trialEnd->greaterThan($end)) {
                $end = $trialEnd->copy();
            }

            $subscription = $this->makeSubscription($business, [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'price' => $price,
                'currency' => config('subscription.currency'),
                'status' => $trialEnd !== null ? SubscriptionStatus::Trial : SubscriptionStatus::Active,
                'starts_at' => $start,
                'ends_at' => $end,
                'trial_ends_at' => $trialEnd,
                'grace_days' => $options['grace_days'] ?? null,
                'notes' => $options['notes'] ?? null,
            ]);

            $this->audit->log(
                'subscription.assigned',
                $subscription,
                "Subscribed to {$plan->name} ({$cycle->label()}).",
                [
                    'plan' => $plan->slug,
                    'billing_cycle' => $cycle->value,
                    'price' => (float) $price,
                    'starts_at' => $start->toDateTimeString(),
                    'ends_at' => $end?->toDateTimeString(),
                ],
            );

            return $subscription;
        });
    }

    /**
     * Renew for another period on the same plan (#83).
     *
     * The new period starts where the old one ended when renewing early, so a
     * customer who pays ahead is not silently robbed of the days they already
     * bought. If the old period is already past, it starts now.
     */
    public function renew(Business $business, ?BillingCycle $cycle = null, array $options = []): Subscription
    {
        $existing = $this->current($business);

        if ($existing === null) {
            throw new InvalidArgumentException('Cannot renew: this business has no subscription.');
        }

        if ($existing->plan === null) {
            throw new InvalidArgumentException('Cannot renew: the subscribed plan no longer exists.');
        }

        $plan = $existing->plan;
        $cycle ??= $existing->billing_cycle;

        return $this->write($business, function () use ($business, $existing, $plan, $cycle, $options): Subscription {
            $this->supersedeCurrent($business);

            $start = $existing->ends_at !== null && $existing->ends_at->isFuture()
                ? $existing->ends_at->copy()
                : now();

            $customDays = $options['custom_days'] ?? $plan->price($cycle)?->custom_days;
            $price = $options['price'] ?? $this->priceFor($plan, $cycle);
            $end = $cycle->expiryFrom($start, $customDays);

            $subscription = $this->makeSubscription($business, [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'price' => $price,
                'currency' => config('subscription.currency'),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $start,
                'ends_at' => $end,
                // A renewal is never a trial, whatever the previous row was.
                'trial_ends_at' => null,
                'grace_days' => $options['grace_days'] ?? $existing->grace_days,
                'notes' => $options['notes'] ?? null,
            ]);

            $this->audit->log(
                'subscription.renewed',
                $subscription,
                "Renewed {$plan->name} ({$cycle->label()}) until ".($end?->toDateString() ?? 'forever').'.',
                [
                    'plan' => $plan->slug,
                    'billing_cycle' => $cycle->value,
                    'price' => (float) $price,
                    'previous_ends_at' => $existing->ends_at?->toDateTimeString(),
                    'ends_at' => $end?->toDateTimeString(),
                ],
            );

            return $subscription;
        });
    }

    /**
     * Move a business to a different plan (#83 upgrade / downgrade).
     *
     * By default the unused days of the current period are carried onto the new
     * one — an upgrade mid-month should not throw away time already paid for.
     * Pass `credit_remaining_days: false` for a clean cut-over.
     */
    public function changePlan(Business $business, Plan $plan, BillingCycle $cycle, array $options = []): Subscription
    {
        $existing = $this->current($business);
        $creditDays = ($options['credit_remaining_days'] ?? true) ? ($existing?->daysRemaining() ?? 0) : 0;

        return $this->write($business, function () use ($business, $plan, $cycle, $options, $existing, $creditDays): Subscription {
            $this->supersedeCurrent($business);

            $start = now();
            $customDays = $options['custom_days'] ?? $plan->price($cycle)?->custom_days;
            $price = $options['price'] ?? $this->priceFor($plan, $cycle);

            $end = $cycle->expiryFrom($start, $customDays);

            if ($end !== null && $creditDays > 0) {
                $end = $end->copy()->addDays($creditDays);
            }

            $subscription = $this->makeSubscription($business, [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'price' => $price,
                'currency' => config('subscription.currency'),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $start,
                'ends_at' => $end,
                'trial_ends_at' => null,
                'grace_days' => $options['grace_days'] ?? null,
                'notes' => $options['notes'] ?? null,
            ]);

            $this->audit->log(
                'subscription.plan_changed',
                $subscription,
                sprintf(
                    'Plan changed from %s to %s (%s).',
                    $existing?->plan?->name ?? 'none',
                    $plan->name,
                    $cycle->label(),
                ),
                [
                    'from_plan' => $existing?->plan?->slug,
                    'to_plan' => $plan->slug,
                    'billing_cycle' => $cycle->value,
                    'price' => (float) $price,
                    'credited_days' => $creditDays,
                    'ends_at' => $end?->toDateTimeString(),
                ],
            );

            return $subscription;
        });
    }

    /**
     * Cancel the current subscription.
     *
     * The row stays "current" rather than being superseded: the tenant and the
     * operator both need to see what was cancelled and why. Access stops
     * immediately — {@see Subscription::effectiveStatus()} checks `cancelled_at`
     * before anything else.
     */
    public function cancel(Business $business, ?string $reason = null): ?Subscription
    {
        $subscription = $this->current($business);

        if ($subscription === null || $subscription->isCancelled()) {
            return $subscription;
        }

        return $this->write($business, function () use ($subscription, $reason): Subscription {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->log(
                'subscription.cancelled',
                $subscription,
                'Subscription cancelled.'.($reason !== null ? " Reason: {$reason}" : ''),
                ['plan' => $subscription->plan?->slug, 'reason' => $reason],
            );

            return $subscription;
        });
    }

    /** Undo a cancellation, provided the paid period has not also run out. */
    public function resume(Business $business): ?Subscription
    {
        $subscription = $this->current($business);

        if ($subscription === null || ! $subscription->isCancelled()) {
            return $subscription;
        }

        return $this->write($business, function () use ($subscription): Subscription {
            $subscription->forceFill([
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ])->save();

            // Recompute from the dates rather than assuming Active.
            $subscription->forceFill(['status' => $subscription->effectiveStatus()])->save();

            $this->audit->log(
                'subscription.resumed',
                $subscription,
                'Cancellation reverted.',
                ['plan' => $subscription->plan?->slug, 'status' => $subscription->status->value],
            );

            return $subscription;
        });
    }

    /**
     * Push the current period out by N days (#83) — goodwill, support credit,
     * a late bank transfer. Mutates the current row: this adjusts a date, it
     * does not rewrite a financial record.
     */
    public function extend(Business $business, int $days, ?string $reason = null): ?Subscription
    {
        $days = (int) $days;

        if ($days === 0) {
            return $this->current($business);
        }

        $subscription = $this->current($business);

        if ($subscription === null) {
            throw new InvalidArgumentException('Cannot extend: this business has no subscription.');
        }

        // Lifetime subscriptions have nothing to extend.
        if ($subscription->ends_at === null) {
            return $subscription;
        }

        return $this->write($business, function () use ($subscription, $days, $reason): Subscription {
            $before = $subscription->ends_at->copy();

            // Extending an already-lapsed subscription counts from today, so
            // "+7 days" always means seven usable days.
            $base = $before->isFuture() ? $before : now();
            $after = $base->copy()->addDays($days);

            $subscription->forceFill([
                'ends_at' => $after,
                'status' => SubscriptionStatus::Active,
            ])->save();

            $this->audit->logChange(
                'subscription.extended',
                $subscription,
                ['ends_at' => $before->toDateTimeString()],
                ['ends_at' => $after->toDateTimeString()],
                "Subscription extended by {$days} day(s)."
                    .($reason !== null ? " Reason: {$reason}" : ''),
            );

            return $subscription;
        });
    }

    /** Give a trial more days (#25). Extends the paid window with it. */
    public function addTrialDays(Business $business, int $days, ?string $reason = null): ?Subscription
    {
        $days = max(1, (int) $days);
        $subscription = $this->current($business);

        if ($subscription === null) {
            throw new InvalidArgumentException('Cannot add trial days: this business has no subscription.');
        }

        return $this->write($business, function () use ($subscription, $days, $reason): Subscription {
            $before = $subscription->trial_ends_at?->copy();

            $base = $before !== null && $before->isFuture() ? $before : now();
            $trialEnd = $base->copy()->addDays($days);

            $attributes = [
                'trial_ends_at' => $trialEnd,
                'status' => SubscriptionStatus::Trial,
            ];

            // Keep ends_at at or beyond the trial, or access would stop first.
            if ($subscription->ends_at !== null && $subscription->ends_at->lessThan($trialEnd)) {
                $attributes['ends_at'] = $trialEnd->copy();
            }

            $subscription->forceFill($attributes)->save();

            $this->audit->logChange(
                'subscription.trial_extended',
                $subscription,
                ['trial_ends_at' => $before?->toDateTimeString()],
                ['trial_ends_at' => $trialEnd->toDateTimeString()],
                "Trial extended by {$days} day(s)."
                    .($reason !== null ? " Reason: {$reason}" : ''),
            );

            return $subscription;
        });
    }

    /**
     * Record money received (#82).
     *
     * ⚠️ FINANCIAL RECORD. Payments are only ever added, never edited away — a
     * mistake is corrected with a `refunded` row. #133 / #198
     *
     * @param  array{amount?: float, method?: string, status?: PaymentStatus|string, reference?: string|null, paid_at?: CarbonInterface|null, notes?: string|null, currency?: string}  $data
     */
    public function recordPayment(Subscription $subscription, array $data = []): SubscriptionPayment
    {
        $business = $subscription->business;

        if ($business === null) {
            throw new InvalidArgumentException('Cannot record a payment for a subscription with no business.');
        }

        $method = $data['method'] ?? (config('subscription.payment_methods')[0] ?? 'other');

        if (! in_array($method, (array) config('subscription.payment_methods'), true)) {
            throw new InvalidArgumentException("Unknown payment method [{$method}].");
        }

        $status = $data['status'] ?? PaymentStatus::Paid;
        $status = $status instanceof PaymentStatus ? $status : PaymentStatus::from((string) $status);

        return $this->write($business, function () use ($subscription, $data, $method, $status): SubscriptionPayment {
            $payment = new SubscriptionPayment;
            $payment->fill([
                'amount' => $data['amount'] ?? (float) $subscription->price,
                'currency' => $data['currency'] ?? $subscription->currency ?? config('subscription.currency'),
                'method' => $method,
                'status' => $status,
                'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->subscription_id = $subscription->id;
            $payment->business_id = $subscription->business_id;
            $payment->recorded_by = auth('admin')->id();
            $payment->save();

            $this->audit->log(
                'subscription.payment_recorded',
                $payment,
                sprintf('%s payment of %s recorded.', $status->label(), $payment->formattedAmount()),
                [
                    'subscription_id' => $subscription->id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'method' => $method,
                    'status' => $status->value,
                    'reference' => $payment->reference,
                ],
            );

            return $payment;
        });
    }

    /**
     * Bring the stored `status` column back in line with the dates.
     *
     * Access never depends on this — {@see Subscription::effectiveStatus()} is
     * always derived — but operator lists and reports need a queryable column,
     * so a scheduled command reconciles it. Returns how many rows changed.
     */
    public function reconcileStatuses(): int
    {
        $changed = 0;

        Subscription::query()
            ->allTenants()
            ->whereNull('superseded_at')
            ->with('plan')
            ->chunkById(200, function ($subscriptions) use (&$changed): void {
                foreach ($subscriptions as $subscription) {
                    $effective = $subscription->effectiveStatus();

                    if ($subscription->status === $effective) {
                        continue;
                    }

                    $before = $subscription->status;
                    $subscription->forceFill(['status' => $effective])->save();
                    $changed++;

                    if ($effective === SubscriptionStatus::Expired) {
                        $this->features->flush($subscription->business_id);
                        $this->limits->flush($subscription->business_id);

                        $this->audit->log(
                            'subscription.expired',
                            $subscription,
                            'Subscription lapsed.',
                            ['from' => $before->value, 'to' => $effective->value],
                            null,
                            $subscription->business_id,
                        );
                    }
                }
            });

        return $changed;
    }

    /** Current subscriptions whose period ends inside N days — for reminders (#11). */
    public function expiringWithin(int $days)
    {
        return Subscription::query()
            ->allTenants()
            ->expiringWithin($days)
            ->with(['plan', 'business'])
            ->orderBy('ends_at')
            ->get();
    }

    // -------------------------------------------------------------- internals

    /**
     * Every mutation funnels through here: correct tenant context, one
     * transaction, caches flushed only after a successful commit.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function write(Business $business, callable $callback)
    {
        $result = $this->tenant->runFor($business, fn () => DB::transaction($callback));

        // After commit, never before: a rolled-back change must not have
        // flushed a cache the old value would then be re-read into.
        $this->features->flush($business->id);
        $this->limits->flush($business->id);

        return $result;
    }

    /**
     * Mark the live subscription as replaced. Uniqueness of "one current row per
     * business" is enforced here inside the transaction rather than by a partial
     * unique index, which MySQL does not support.
     */
    protected function supersedeCurrent(Business $business): void
    {
        Subscription::query()
            ->forBusiness($business->id)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }

    /**
     * `business_id` is not fillable (#132), so it is assigned directly. Inside
     * {@see self::write()} the tenant trait would stamp the same value anyway —
     * setting it explicitly keeps the service usable from a console command with
     * no ambient context.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSubscription(Business $business, array $attributes): Subscription
    {
        $subscription = new Subscription;
        $subscription->fill($attributes);
        $subscription->business_id = $business->id;
        $subscription->created_by = auth('admin')->id();
        $subscription->save();

        return $subscription->refresh();
    }

    /**
     * The snapshot price for a plan + cycle. Throws rather than defaulting to 0:
     * silently subscribing someone for free is worse than a loud failure.
     */
    protected function priceFor(Plan $plan, BillingCycle $cycle): float
    {
        $price = $plan->price($cycle);

        if ($price === null) {
            throw new InvalidArgumentException(
                "Plan [{$plan->slug}] has no active {$cycle->label()} price."
            );
        }

        return (float) $price->price;
    }

    protected function resolveBusinessId(int|Business|null $business): ?int
    {
        return match (true) {
            $business instanceof Business => $business->id,
            is_int($business) => $business,
            default => $this->tenant->businessId(),
        };
    }
}
