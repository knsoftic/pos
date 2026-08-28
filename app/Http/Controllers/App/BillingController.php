<?php

namespace App\Http\Controllers\App;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Services\SubscriptionService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * The tenant's own billing screen (/app/billing) — #11, #78, #84.
 *
 * Reachable even when the subscription has lapsed: CheckSubscription allow-lists
 * the `app.billing.*` routes, and both {@see \App\Exceptions\FeatureUnavailableException}
 * and the expiry gate redirect here. If this page could be locked out, an expired
 * tenant would have no way to see why they were locked out.
 *
 * Read-only by design. Plan changes and payments are operator actions in this
 * release (#82) — self-serve checkout is a later phase — so the upgrade panel
 * shows what to ask for rather than pretending to charge a card.
 */
class BillingController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected FeatureService $features,
        protected PlanLimitService $limits,
    ) {}

    public function index(TenantContext $tenant): View
    {
        $business = $tenant->business();
        $subscription = $this->subscriptions->current($business);

        return view('app.billing.index', [
            'business' => $business,
            'subscription' => $subscription,
            'plan' => $subscription?->plan,
            'status' => $subscription?->effectiveStatus(),
            // #11 — which warning window we are in, if any (7 / 3 / 1 days).
            'warningThreshold' => $subscription?->expiryWarningThreshold(),
            'daysRemaining' => $subscription?->daysRemaining(),
            'graceDaysRemaining' => $subscription?->graceDaysRemaining(),
            'isInGrace' => (bool) $subscription?->isInGrace(),
            'onTrial' => (bool) $subscription?->isOnTrial(),
            // #78 — "350 / 500" usage meters. Ceilings come from the cache, the
            // usage numbers are always counted live.
            'meters' => $this->limits->meters(),
            'enabledFeatures' => $this->features->enabledCodes(),
            'featureGroups' => Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'history' => $this->subscriptions->history($business),
            'payments' => SubscriptionPayment::query()->latestFirst()->limit(12)->get(),
            'amountPaid' => $subscription?->amountPaid() ?? 0.0,
        ]);
    }

    /**
     * Side-by-side plan comparison (#84).
     *
     * Only public, active plans are listed — private/negotiated plans exist for
     * the operator to assign, not for tenants to browse. The tenant's current
     * plan is always included even if it has since been hidden, otherwise the
     * page would compare them against a row they are not on.
     */
    public function plans(TenantContext $tenant): View
    {
        $business = $tenant->business();
        $subscription = $this->subscriptions->current($business);
        $currentPlanId = $subscription?->plan_id;

        $plans = Plan::query()
            ->with(['prices', 'features', 'limits'])
            ->where(fn ($q) => $q
                ->where(fn ($inner) => $inner->where('is_active', true)->where('is_public', true))
                ->when($currentPlanId !== null, fn ($inner) => $inner->orWhere('id', $currentPlanId)))
            ->ordered()
            ->get();

        return view('app.billing.plans', [
            'business' => $business,
            'subscription' => $subscription,
            'currentPlanId' => $currentPlanId,
            'plans' => $plans,
            'cycles' => BillingCycle::cases(),
            'features' => Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'limits' => Limit::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'limitGroupLabels' => LimitRegistry::groupLabels(),
            // plan_id => [feature_id => bool]
            'featureMap' => $plans->mapWithKeys(fn (Plan $p) => [
                $p->id => $p->features->mapWithKeys(fn ($f) => [$f->id => (bool) $f->pivot->is_enabled])->all(),
            ])->all(),
            // plan_id => [limit_id => int|null]  (null = unlimited)
            'limitMap' => $plans->mapWithKeys(fn (Plan $p) => [
                $p->id => $p->limits->mapWithKeys(fn ($l) => [$l->id => $l->pivot->value])->all(),
            ])->all(),
        ]);
    }
}
