<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Operator actions on a tenant's subscription (#82, #83, #176).
 *
 * Every method is a thin, validated wrapper: {@see SubscriptionService} owns the
 * transaction, the append-only history, the audit entry and the cache flush. A
 * controller must never write a subscription row directly, or those four
 * guarantees stop holding.
 */
class BusinessSubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    /** Every subscription across every tenant — the operator's billing overview. */
    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'plan' => (string) $request->query('plan', ''),
            'expiring' => (string) $request->query('expiring', ''),
        ];

        $query = Subscription::query()
            ->allTenants()
            ->whereNull('superseded_at')
            ->with(['business:id,name,slug,status', 'plan:id,name,slug'])
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['plan'] !== '', fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('slug', $filters['plan'])))
            ->when($filters['expiring'] !== '', fn ($q) => $q
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [now(), now()->addDays((int) $filters['expiring'])]))
            ->orderByRaw('ends_at IS NULL')  // lifetime rows last
            ->orderBy('ends_at');

        return view('admin.subscriptions.index', [
            'subscriptions' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'plans' => Plan::query()->ordered()->get(['id', 'name', 'slug']),
            'statuses' => \App\Enums\SubscriptionStatus::options(),
            'revenue' => [
                'collected' => \App\Models\SubscriptionPayment::query()->allTenants()->paid()->sum('amount'),
                'this_month' => \App\Models\SubscriptionPayment::query()->allTenants()->paid()
                    ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('amount'),
                'pending' => \App\Models\SubscriptionPayment::query()->allTenants()
                    ->where('status', PaymentStatus::Pending)->sum('amount'),
            ],
            'expiringSoon' => $this->subscriptions->expiringWithin(
                max((array) config('subscription.warning_days')) ?: 7
            ),
        ]);
    }

    /** Assign or switch the plan (#83). */
    public function store(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::in(BillingCycle::values())],
            'mode' => ['required', Rule::in(['assign', 'change', 'trial'])],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'credit_remaining_days' => ['nullable', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $cycle = BillingCycle::from($validated['billing_cycle']);

        try {
            $subscription = match ($validated['mode']) {
                'trial' => $this->subscriptions->startTrial(
                    $business,
                    $plan,
                    isset($validated['trial_days']) ? (int) $validated['trial_days'] : null,
                    $validated['notes'] ?? null,
                ),
                'change' => $this->subscriptions->changePlan($business, $plan, $cycle, [
                    'credit_remaining_days' => $request->boolean('credit_remaining_days'),
                    'price' => $validated['price'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]),
                default => $this->subscriptions->assign($business, $plan, $cycle, [
                    'price' => $validated['price'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['plan_id' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            '%s is now on %s until %s.',
            $business->name,
            $plan->name,
            $subscription->ends_at?->toFormattedDateString() ?? 'forever',
        ));
    }

    /** Renew for another period (#83). */
    public function renew(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'billing_cycle' => ['nullable', Rule::in(BillingCycle::values())],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $subscription = $this->subscriptions->renew(
                $business,
                isset($validated['billing_cycle']) ? BillingCycle::from($validated['billing_cycle']) : null,
                ['price' => $validated['price'] ?? null],
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Renewed until '.($subscription->ends_at?->toFormattedDateString() ?? 'forever').'.');
    }

    /** Push the expiry out by N days — goodwill or a late payment (#83). */
    public function extend(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $subscription = $this->subscriptions->extend(
                $business,
                (int) $validated['days'],
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($subscription?->ends_at === null) {
            return back()->with('error', 'This subscription never expires, so there is nothing to extend.');
        }

        return back()->with('success', sprintf(
            'Extended by %d day(s) — now valid until %s.',
            $validated['days'],
            $subscription->ends_at->toFormattedDateString(),
        ));
    }

    /** Give a trial more time (#25). */
    public function addTrialDays(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $subscription = $this->subscriptions->addTrialDays(
                $business,
                (int) $validated['days'],
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Trial extended — now runs until %s.',
            $subscription?->trial_ends_at?->toFormattedDateString() ?? 'unknown',
        ));
    }

    public function cancel(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $subscription = $this->subscriptions->cancel($business, $validated['reason'] ?? null);

        if ($subscription === null) {
            return back()->with('error', 'This business has no subscription to cancel.');
        }

        return back()->with('success', 'Subscription cancelled. The tenant loses access immediately.');
    }

    public function resume(Business $business): RedirectResponse
    {
        $subscription = $this->subscriptions->resume($business);

        if ($subscription === null) {
            return back()->with('error', 'This business has no subscription.');
        }

        return back()->with('success', 'Cancellation reverted — status is now '.$subscription->effectiveStatus()->label().'.');
    }

    /**
     * Record money received (#82).
     *
     * ⚠️ Append-only. A mistake is corrected with a `refunded` row, never by
     * editing or deleting this one. #133 / #198
     */
    public function recordPayment(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'subscription_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'method' => ['required', Rule::in((array) config('subscription.payment_methods'))],
            'status' => ['required', Rule::in(PaymentStatus::values())],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Scoped to this business: a subscription id from another tenant must not
        // be payable from here. #117
        $subscription = Subscription::query()
            ->forBusiness($business->id)
            ->find($validated['subscription_id']);

        if ($subscription === null) {
            return back()->with('error', 'That subscription does not belong to this business.');
        }

        $payment = $this->subscriptions->recordPayment($subscription, [
            'amount' => (float) $validated['amount'],
            'method' => $validated['method'],
            'status' => PaymentStatus::from($validated['status']),
            'reference' => $validated['reference'] ?? null,
            'paid_at' => $validated['paid_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', "Payment of {$payment->formattedAmount()} recorded.");
    }
}
