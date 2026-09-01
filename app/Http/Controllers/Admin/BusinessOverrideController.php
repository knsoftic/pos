<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessFeatureOverride;
use App\Models\BusinessLimitOverride;
use App\Models\Feature;
use App\Models\Limit;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-business feature grants and quota overrides (#10, #177).
 *
 * These rows sit ABOVE the plan in the resolution order, so they are the sharp
 * tool for "this one customer negotiated something different". Three rules:
 *
 *  1. Absent row = inherit the plan. Reverting to inheritance therefore DELETES
 *     the row rather than writing a value that happens to match the plan — a
 *     later plan change must flow through.
 *  2. A limit `value` of NULL means unlimited; 0 means nothing allowed.
 *  3. Every write is audited with a reason, and flushes the tenant's caches.
 */
class BusinessOverrideController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    /** Full override editor for one tenant — every feature and quota on one page. */
    public function index(Business $business): View
    {
        $business->loadMissing('currentSubscription.plan');

        return view('admin.businesses.overrides', [
            'business' => $business,
            'subscription' => $business->currentSubscription,
            'featureGroups' => Feature::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'limitGroups' => Limit::query()->where('is_active', true)->ordered()->get()->groupBy('group'),
            'featureGroupLabels' => FeatureRegistry::groupLabels(),
            'limitGroupLabels' => LimitRegistry::groupLabels(),
            // Effective answers (plan + overrides applied) so the operator sees
            // the outcome, not just the raw rows.
            'effectiveFeatures' => $this->features->all($business),
            'meters' => $this->limits->meters(null, $business),
            'featureOverrides' => $business->featureOverrides()->get()->keyBy('feature_id'),
            'limitOverrides' => $business->limitOverrides()->get()->keyBy('limit_id'),
            // What the plan alone would say, for the "inherited" column.
            'planFeatureIds' => $business->currentSubscription?->plan
                ?->features()->wherePivot('is_enabled', true)->pluck('features.id')->all() ?? [],
            // Same idea for quotas. Keyed by limit id, value NULL = unlimited; a
            // MISSING key means the plan configured nothing, so the registry
            // default applies — the two cases must stay distinguishable.
            'planLimitValues' => $business->currentSubscription?->plan
                ?->limits()->get()
                ->mapWithKeys(fn (Limit $limit) => [$limit->id => $limit->pivot->value])
                ->all() ?? [],
        ]);
    }

    /** Grant or revoke one feature for this tenant, regardless of its plan. */
    public function storeFeature(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'is_enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $feature = Feature::findOrFail($validated['feature_id']);
        $enabled = (bool) $validated['is_enabled'];

        $override = BusinessFeatureOverride::query()->updateOrCreate(
            ['business_id' => $business->id, 'feature_id' => $feature->id],
            [
                'is_enabled' => $enabled,
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user('admin')?->id,
            ],
        );

        $this->audit->log(
            'business.feature_override_set',
            $override,
            sprintf('Feature "%s" %s for %s by override.', $feature->name, $enabled ? 'granted' : 'revoked', $business->name),
            [
                'feature' => $feature->code,
                'is_enabled' => $enabled,
                'reason' => $validated['reason'] ?? null,
            ],
            null,
            $business->id,
        );

        $this->features->flush($business->id);

        return back()->with('success', sprintf(
            '"%s" is now %s for this business, overriding the plan.',
            $feature->name,
            $enabled ? 'enabled' : 'disabled',
        ));
    }

    /** Drop the override so the plan decides again. */
    public function destroyFeature(Request $request, Business $business, Feature $feature): RedirectResponse
    {
        $override = BusinessFeatureOverride::query()
            ->where('business_id', $business->id)
            ->where('feature_id', $feature->id)
            ->first();

        if ($override === null) {
            return back()->with('error', 'There is no override on that feature — it already follows the plan.');
        }

        $override->delete();

        $this->audit->log(
            'business.feature_override_cleared',
            $business,
            "Feature \"{$feature->name}\" override cleared for {$business->name} — it follows the plan again.",
            ['feature' => $feature->code, 'was_enabled' => $override->is_enabled],
            null,
            $business->id,
        );

        $this->features->flush($business->id);

        return back()->with('success', "\"{$feature->name}\" follows the plan again.");
    }

    /**
     * Set a custom quota. `unlimited` wins over the number box, matching the
     * plan editor, so an operator cannot submit a contradictory pair.
     */
    public function storeLimit(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'limit_id' => ['required', 'integer', 'exists:limits,id'],
            'value' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'unlimited' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $limit = Limit::findOrFail($validated['limit_id']);
        $unlimited = $request->boolean('unlimited');
        $value = $unlimited ? null : (int) ($validated['value'] ?? 0);

        $override = BusinessLimitOverride::query()->updateOrCreate(
            ['business_id' => $business->id, 'limit_id' => $limit->id],
            [
                'value' => $value,
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user('admin')?->id,
            ],
        );

        $this->audit->log(
            'business.limit_override_set',
            $override,
            sprintf('Limit "%s" set to %s for %s by override.', $limit->name, $unlimited ? 'unlimited' : (string) $value, $business->name),
            [
                'limit' => $limit->code,
                'value' => $value,
                'unlimited' => $unlimited,
                'reason' => $validated['reason'] ?? null,
            ],
            null,
            $business->id,
        );

        $this->limits->flush($business->id);

        return back()->with('success', sprintf(
            '"%s" is now %s for this business.',
            $limit->name,
            $unlimited ? 'unlimited' : number_format((float) $value),
        ));
    }

    public function destroyLimit(Request $request, Business $business, Limit $limit): RedirectResponse
    {
        $override = BusinessLimitOverride::query()
            ->where('business_id', $business->id)
            ->where('limit_id', $limit->id)
            ->first();

        if ($override === null) {
            return back()->with('error', 'There is no override on that limit — it already follows the plan.');
        }

        $override->delete();

        $this->audit->log(
            'business.limit_override_cleared',
            $business,
            "Limit \"{$limit->name}\" override cleared for {$business->name} — it follows the plan again.",
            ['limit' => $limit->code, 'was_value' => $override->value],
            null,
            $business->id,
        );

        $this->limits->flush($business->id);

        return back()->with('success', "\"{$limit->name}\" follows the plan again.");
    }
}
