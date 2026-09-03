<?php

namespace App\Http\Controllers\App;

use App\Enums\BillingCycle;
use App\Exceptions\FeatureUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\SubscriptionPayment;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Services\PlanRequestService;
use App\Services\SubscriptionService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tenant's own billing screen (/app/billing) — #11, #78, #84.
 *
 * Reachable even when the subscription has lapsed: CheckSubscription allow-lists
 * the `app.billing.*` routes, and both {@see FeatureUnavailableException}
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
        protected PlanRequestService $planRequests,
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
            'openRequest' => PlanRequest::query()->pending()->with('plan')->first(),
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

    /**
     * "I want this plan." (#82)
     *
     * ⚠️ FILES A REQUEST, CHANGES NOTHING. No self-serve checkout in this
     * release -- the operator moves the shop and takes the money. What this
     * fixes is the button that used to be a mailto:, which needed a configured
     * mail client on the shopkeeper's device and left no trace anywhere when
     * there wasn't one.
     *
     * A private plan cannot be asked for: those exist for the operator to
     * assign, and letting a shop request one by id would turn a negotiated
     * price into a menu item.
     */
    public function requestPlan(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($plan->is_active && $plan->is_public, 404);

        $cycle = BillingCycle::tryFrom((string) $request->input('cycle'));

        $planRequest = $this->planRequests->open($plan, $cycle);

        return back()
            ->with('success', "Request sent for \"{$plan->name}\". We will contact you shortly.")
            // The view offers this as a button rather than redirecting into it:
            // a redirect off-site would lose the confirmation the shop just got.
            ->with('whatsapp', $this->planRequests->whatsappLink($planRequest));
    }
}
