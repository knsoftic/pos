<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Plan catalogue management (/admin/plans) — #84, #190.
 *
 * The plan, its prices, its feature matrix and its quota matrix are edited and
 * saved as ONE unit inside a transaction: a half-saved plan would silently
 * change what existing subscribers are entitled to.
 *
 * Every save flushes the entitlement caches for ALL businesses. That is blunt,
 * but a plan change affects every subscriber and a stale cache here means a
 * customer keeps a feature they just lost (or loses one they just bought).
 *
 * NOT tenant-scoped: the operator console has no TenantContext.
 */
class PlanController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    public function index(): View
    {
        $plans = Plan::query()
            ->withCount(['subscriptions' => fn ($q) => $q->whereNull('superseded_at')])
            ->with('prices')
            ->ordered()
            ->get();

        return view('admin.plans.index', [
            'plans' => $plans,
            'featureCount' => Feature::query()->where('is_active', true)->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.create', $this->formData(new Plan([
            'is_active' => true,
            'is_public' => true,
            'sort_order' => (int) (Plan::query()->max('sort_order') ?? 0) + 10,
        ])));
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request): Plan {
            $plan = new Plan($request->planAttributes());
            $plan->created_by = $request->user('admin')?->id;
            $plan->save();

            $this->syncPrices($plan, $request->priceRows());
            $this->syncMatrices($plan, $request->enabledFeatureIds(), $request->limitValues());

            $this->audit->log(
                'plan.created',
                $plan,
                "Plan \"{$plan->name}\" created.",
                ['slug' => $plan->slug, 'prices' => $request->priceRows()],
            );

            return $plan;
        });

        $this->flushEntitlementCaches();

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', "Plan \"{$plan->name}\" created.");
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', $this->formData($plan));
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan): void {
            $before = $plan->only(['name', 'slug', 'is_active', 'is_public', 'trial_days', 'grace_days']);

            $plan->fill($request->planAttributes())->save();

            $this->syncPrices($plan, $request->priceRows());
            $this->syncMatrices($plan, $request->enabledFeatureIds(), $request->limitValues());

            $this->audit->logChange(
                'plan.updated',
                $plan,
                $before,
                $plan->only(['name', 'slug', 'is_active', 'is_public', 'trial_days', 'grace_days']),
                "Plan \"{$plan->name}\" updated.",
            );
        });

        $this->flushEntitlementCaches();

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', 'Plan saved. Subscriber entitlements have been refreshed.');
    }

    /**
     * Archive a plan (#104). Soft delete only, and refused while anyone is still
     * on it — deleting a plan out from under paying subscribers would leave them
     * with a subscription pointing at nothing. Deactivate it instead to stop new
     * signups while honouring existing ones.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->isInUse()) {
            return back()->with('error', sprintf(
                'Cannot archive "%s": %d business(es) are still subscribed. Deactivate it instead — existing subscribers keep working and nobody new can sign up.',
                $plan->name,
                $plan->subscriptions()->whereNull('superseded_at')->count(),
            ));
        }

        $plan->delete();

        $this->audit->log('plan.archived', $plan, "Plan \"{$plan->name}\" archived.", ['slug' => $plan->slug]);
        $this->flushEntitlementCaches();

        return redirect()
            ->route('admin.plans.index')
            ->with('success', "Plan \"{$plan->name}\" archived.");
    }

    /** Quick on/off from the list, without opening the full editor. */
    public function toggle(Plan $plan): RedirectResponse
    {
        $plan->is_active = ! $plan->is_active;
        $plan->save();

        $this->audit->log(
            'plan.'.($plan->is_active ? 'activated' : 'deactivated'),
            $plan,
            sprintf('Plan "%s" %s.', $plan->name, $plan->is_active ? 'activated' : 'deactivated'),
        );

        $this->flushEntitlementCaches();

        return back()->with('success', sprintf(
            '"%s" is now %s.',
            $plan->name,
            $plan->is_active ? 'active' : 'inactive (existing subscribers are unaffected)',
        ));
    }

    /** Side-by-side comparison of every plan's features and quotas (#84). */
    public function matrix(): View
    {
        $plans = Plan::query()
            ->with(['features', 'limits', 'prices'])
            ->ordered()
            ->get();

        return view('admin.plans.matrix', [
            'plans' => $plans,
            'features' => Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'limits' => Limit::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'limitGroupLabels' => LimitRegistry::groupLabels(),
            // plan_id => [feature_id => bool]
            'featureMap' => $plans->mapWithKeys(fn (Plan $p) => [
                $p->id => $p->features->mapWithKeys(fn ($f) => [$f->id => (bool) $f->pivot->is_enabled])->all(),
            ])->all(),
            // plan_id => [limit_id => int|null]
            'limitMap' => $plans->mapWithKeys(fn (Plan $p) => [
                $p->id => $p->limits->mapWithKeys(fn ($l) => [$l->id => $l->pivot->value])->all(),
            ])->all(),
        ]);
    }

    // -------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    protected function formData(Plan $plan): array
    {
        $plan->loadMissing(['prices', 'features', 'limits']);

        return [
            'plan' => $plan,
            'cycles' => BillingCycle::cases(),
            // cycle value => ['price' => …, 'custom_days' => …] for repopulation
            'existingPrices' => $plan->prices->keyBy(fn ($p) => $p->billing_cycle->value),
            'featureGroups' => Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'limitGroups' => Limit::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'limitGroupLabels' => LimitRegistry::groupLabels(),
            'selectedFeatureIds' => $plan->features
                ->filter(fn ($f) => (bool) $f->pivot->is_enabled)
                ->pluck('id')
                ->all(),
            // limit_id => value (may be null = unlimited)
            'planLimits' => $plan->limits->mapWithKeys(fn ($l) => [$l->id => $l->pivot->value])->all(),
            // limit_id => whether a pivot row exists at all
            'configuredLimitIds' => $plan->limits->pluck('id')->all(),
        ];
    }

    /**
     * Replace the plan's price rows with exactly what was submitted.
     *
     * Rows are updated in place where the cycle still exists so their ids (and
     * therefore any future references) survive; removed cycles are deleted.
     *
     * @param  array<string, array{price: float, custom_days: int|null}>  $rows
     */
    protected function syncPrices(Plan $plan, array $rows): void
    {
        foreach ($rows as $cycle => $row) {
            $plan->prices()->updateOrCreate(
                ['billing_cycle' => $cycle],
                [
                    'price' => $row['price'],
                    'custom_days' => $row['custom_days'],
                    'is_active' => true,
                ],
            );
        }

        $plan->prices()
            ->whereNotIn('billing_cycle', array_keys($rows))
            ->delete();
    }

    /**
     * @param  list<int>  $enabledFeatureIds
     * @param  array<int, int|null>  $limitValues
     */
    protected function syncMatrices(Plan $plan, array $enabledFeatureIds, array $limitValues): void
    {
        // Every active feature gets an explicit row, so "off" and "unconfigured"
        // are distinguishable in the comparison matrix (#84).
        $featurePivot = [];

        foreach (Feature::query()->where('is_active', true)->pluck('id') as $featureId) {
            $featurePivot[$featureId] = ['is_enabled' => in_array((int) $featureId, $enabledFeatureIds, true)];
        }

        $plan->features()->sync($featurePivot);

        $limitPivot = [];

        foreach ($limitValues as $limitId => $value) {
            $limitPivot[$limitId] = ['value' => $value];
        }

        $plan->limits()->sync($limitPivot);
    }

    /**
     * A plan edit can change entitlements for every subscriber at once, and
     * there is no cheap way to enumerate them mid-transaction, so both caches
     * are cleared wholesale.
     */
    protected function flushEntitlementCaches(): void
    {
        $this->features->flushAll();
        $this->limits->flushAll();
    }
}
