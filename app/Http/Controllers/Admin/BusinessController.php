<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessRequest;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Services\SubscriptionService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\View\View;

/**
 * Tenant management from the operator console (/admin/businesses) — #6, #126.
 *
 * NOT tenant-scoped: this controller deliberately reads across every tenant, so
 * any query touching a tenant model must be explicit — `forBusiness($id)` or
 * `allTenants()`. Never rely on an ambient context here, because there isn't one.
 */
class BusinessController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'plan' => (string) $request->query('plan', ''),
            'subscription' => (string) $request->query('subscription', ''),
        ];

        $businesses = Business::query()
            ->with(['currentSubscription.plan'])
            ->withCount(['users' => fn ($q) => $q->allTenants()])
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';

                // Bound parameters throughout — never string-interpolated. #135
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['plan'] !== '', fn ($q) => $q->whereHas(
                'currentSubscription.plan',
                fn ($p) => $p->where('slug', $filters['plan'])
            ))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Effective status is derived, not stored, so this filter is applied to
        // the fetched page rather than in SQL. Documented in the UI as such.
        if ($filters['subscription'] !== '') {
            $businesses->setCollection(
                $businesses->getCollection()->filter(function (Business $business) use ($filters) {
                    $status = $business->currentSubscription?->effectiveStatus()->value ?? 'none';

                    return $status === $filters['subscription'];
                })->values()
            );
        }

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'filters' => $filters,
            'plans' => Plan::query()->ordered()->get(['id', 'name', 'slug']),
            'statuses' => Business::statusOptions(),
            'stats' => [
                'total' => Business::count(),
                'active' => Business::where('status', Business::STATUS_ACTIVE)->count(),
                'suspended' => Business::where('status', Business::STATUS_SUSPENDED)->count(),
                'trialing' => Subscription::query()->allTenants()
                    ->whereNull('superseded_at')
                    ->whereNull('cancelled_at')
                    ->where('trial_ends_at', '>', now())
                    ->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.businesses.create', [
            'business' => new Business([
                'status' => Business::STATUS_ACTIVE,
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => config('app.locale', 'en'),
            ]),
            'statuses' => Business::statusOptions(),
            'plans' => Plan::query()->with('prices')->active()->ordered()->get(),
            'cycles' => BillingCycle::cases(),
            'defaultTrialDays' => (int) config('subscription.trial_days'),
        ]);
    }

    /**
     * Create the tenant, its owner login and its first subscription in ONE
     * transaction. A business with no owner (or no entitlement) is not a usable
     * tenant, so a partial success must not survive. #131
     */
    public function store(BusinessRequest $request): RedirectResponse
    {
        $plan = Plan::findOrFail($request->integer('plan_id'));
        $cycle = BillingCycle::tryFrom((string) $request->input('billing_cycle'));

        if ($cycle === null) {
            return back()->withInput()->withErrors(['billing_cycle' => 'Choose a valid billing cycle.']);
        }

        $wantsTrial = $request->boolean('start_trial');

        // Validate the plan can actually be sold on this cycle before writing
        // anything, so the operator gets a field error rather than an exception.
        if (! $wantsTrial && $plan->price($cycle) === null) {
            return back()->withInput()->withErrors([
                'billing_cycle' => "\"{$plan->name}\" has no active {$cycle->label()} price. Pick another cycle or add the price to the plan.",
            ]);
        }

        $business = DB::transaction(function () use ($request, $plan, $cycle, $wantsTrial): Business {
            $business = new Business($request->businessAttributes());
            $business->created_by = $request->user('admin')?->id;
            $business->save();

            // business_id / is_business_owner are guarded (#132) — assigned here
            // explicitly, never mass-assigned from the request.
            $owner = new User([
                'name' => $request->string('owner_name')->toString(),
                'email' => $request->string('owner_email')->toString(),
                'phone' => $request->input('owner_phone'),
                'password' => $request->string('owner_password')->toString(),
            ]);
            $owner->business_id = $business->id;
            $owner->is_business_owner = true;
            $owner->is_active = true;
            $owner->save();

            $this->audit->log(
                'business.created',
                $business,
                "Business \"{$business->name}\" created with owner {$owner->email}.",
                ['slug' => $business->slug, 'owner_email' => $owner->email, 'plan' => $plan->slug],
                null,
                $business->id,
            );

            if ($wantsTrial) {
                $this->subscriptions->startTrial(
                    $business,
                    $plan,
                    $request->input('trial_days') !== null ? (int) $request->input('trial_days') : null,
                );
            } else {
                $this->subscriptions->assign($business, $plan, $cycle);
            }

            return $business;
        });

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('success', "\"{$business->name}\" created and subscribed to {$plan->name}.");
    }

    /** The tenant detail screen: entitlements, usage, history, notes (#126). */
    public function show(Business $business): View
    {
        $business->load([
            'currentSubscription.plan',
            'notes' => fn ($q) => $q->ordered()->limit(50),
            'notes.admin:id,name',
        ]);

        $subscription = $business->currentSubscription;

        return view('admin.businesses.show', [
            'business' => $business,
            'subscription' => $subscription,
            'history' => $this->subscriptions->history($business),
            'payments' => $business->subscriptionPayments()
                ->allTenants()
                ->with('recorder:id,name')
                ->latestFirst()
                ->limit(25)
                ->get(),
            'users' => User::query()->forBusiness($business->id)->orderByDesc('is_business_owner')->get(),
            'meters' => $this->limits->meters(null, $business),
            'featureMap' => $this->features->all($business),
            'featureGroups' => \App\Models\Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'limitGroupLabels' => LimitRegistry::groupLabels(),
            'featureOverrides' => $business->featureOverrides()->with('feature:id,code,name')->get()->keyBy('feature_id'),
            'limitOverrides' => $business->limitOverrides()->with('limit:id,code,name')->get()->keyBy('limit_id'),
            'plans' => Plan::query()->with('prices')->active()->ordered()->get(),
            'cycles' => BillingCycle::cases(),
            'paymentMethods' => (array) config('subscription.payment_methods'),
        ]);
    }

    public function edit(Business $business): View
    {
        return view('admin.businesses.edit', [
            'business' => $business,
            'statuses' => Business::statusOptions(),
        ]);
    }

    public function update(BusinessRequest $request, Business $business): RedirectResponse
    {
        $before = $business->only(['name', 'slug', 'email', 'phone', 'status', 'timezone', 'locale']);

        $business->fill($request->businessAttributes())->save();

        $this->audit->logChange(
            'business.updated',
            $business,
            $before,
            $business->only(['name', 'slug', 'email', 'phone', 'status', 'timezone', 'locale']),
            "Business \"{$business->name}\" updated.",
        );

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('success', 'Business updated.');
    }

    /**
     * Suspend a tenant (#6). Takes effect on the very next request:
     * SetBusinessTenant logs out any user whose business is not active, so an
     * already-signed-in session cannot outlive the suspension. #130
     */
    public function suspend(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($business->isSuspended()) {
            return back()->with('error', 'This business is already suspended.');
        }

        $business->status = Business::STATUS_SUSPENDED;
        $business->save();

        $this->audit->log(
            'business.suspended',
            $business,
            'Business suspended.'.(isset($validated['reason']) ? " Reason: {$validated['reason']}" : ''),
            ['reason' => $validated['reason'] ?? null],
        );

        return back()->with('success', "\"{$business->name}\" suspended. Its users are signed out on their next request.");
    }

    public function activate(Business $business): RedirectResponse
    {
        if ($business->isActive()) {
            return back()->with('error', 'This business is already active.');
        }

        $business->status = Business::STATUS_ACTIVE;
        $business->save();

        $this->audit->log('business.activated', $business, 'Business reactivated.');

        return back()->with('success', "\"{$business->name}\" is active again.");
    }

    /**
     * Send a password reset link to one of the tenant's users (#6).
     *
     * Deliberately a LINK, not an operator-chosen password: the operator never
     * sees or transports a credential, the link is single-use and expires, and
     * the whole thing is auditable. A support call that needs a spoken password
     * should use impersonation instead.
     */
    public function resetUserPassword(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        // Scoped to THIS business — an operator must not be able to aim the
        // reset at a user id belonging to a different tenant. #117
        $user = User::query()->forBusiness($business->id)->find($validated['user_id']);

        if ($user === null) {
            return back()->with('error', 'That user does not belong to this business.');
        }

        $status = PasswordBroker::broker()->sendResetLink(['email' => $user->email]);

        $this->audit->log(
            'business.password_reset_sent',
            $user,
            "Password reset link sent to {$user->email} by an operator.",
            ['email' => $user->email, 'broker_status' => $status],
            null,
            $business->id,
        );

        return back()->with(
            $status === PasswordBroker::RESET_LINK_SENT ? 'success' : 'error',
            $status === PasswordBroker::RESET_LINK_SENT
                ? "Reset link sent to {$user->email}."
                : 'Could not send the reset link. Check the mail configuration and try again.'
        );
    }

    /**
     * Archive a tenant. Soft delete: a business owns invoices and financial
     * history that must remain auditable, so nothing is ever hard-deleted (#104,
     * #133). Suspended-then-archived is the intended path.
     */
    public function destroy(Business $business): RedirectResponse
    {
        $business->delete();

        $this->audit->log('business.archived', $business, "Business \"{$business->name}\" archived.");

        return redirect()
            ->route('admin.businesses.index')
            ->with('success', "\"{$business->name}\" archived. Its data is retained and can be restored.");
    }
}
