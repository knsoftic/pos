<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Models\Business;
use App\Models\BusinessFeatureOverride;
use App\Models\Feature;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Answers "may this business use feature X?" (#183, #13, #187).
 *
 * RESOLUTION ORDER — first level that has an opinion wins:
 *   1. `business_feature_overrides` — an operator's explicit yes/no for this one
 *      tenant (#10). A missing row is not "no", it is "no opinion".
 *   2. `plan_feature` — what the subscribed plan says.
 *   3. `features.default_enabled` — what an unconfigured plan does.
 *
 * NO CURRENT SUBSCRIPTION → EVERYTHING FALSE. A tenant without an entitlement
 * gets no features; the registry defaults are the fallback for a plan that has
 * not been configured, not for having no plan at all. Fail closed.
 *
 * EXPIRY IS DELIBERATELY NOT CHECKED HERE. "Is it in the plan" and "is the plan
 * still paid for" are different questions with different remedies, and an
 * expired tenant on `read_only` behaviour still needs feature answers to render
 * its own data. {@see \App\Http\Middleware\CheckSubscription} owns the expiry
 * gate. Nothing here decides which plan gets what — that is operator data (#190).
 */
class FeatureService
{
    /** Per-request memo, so one request resolves each business at most once. */
    protected array $memo = [];

    public function __construct(protected TenantContext $tenant) {}

    /**
     * @param  int|Business|null  $business  Defaults to the active tenant.
     */
    public function enabled(string $code, int|Business|null $business = null): bool
    {
        return (bool) ($this->all($business)[$code] ?? false);
    }

    public function disabled(string $code, int|Business|null $business = null): bool
    {
        return ! $this->enabled($code, $business);
    }

    /** True only if every one of the given codes is enabled. */
    public function allOf(array $codes, int|Business|null $business = null): bool
    {
        $map = $this->all($business);

        foreach ($codes as $code) {
            if (! ($map[$code] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /** True if at least one of the given codes is enabled. */
    public function anyOf(array $codes, int|Business|null $business = null): bool
    {
        $map = $this->all($business);

        foreach ($codes as $code) {
            if ($map[$code] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The full resolved map for a business: code => bool, covering every code in
     * the registry so callers can rely on the key existing.
     *
     * @return array<string, bool>
     */
    public function all(int|Business|null $business = null): array
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return $this->noneEnabled();
        }

        if (array_key_exists($businessId, $this->memo)) {
            return $this->memo[$businessId];
        }

        $ttl = (int) config('subscription.cache_ttl');

        $resolved = $ttl > 0
            ? Cache::remember($this->cacheKey($businessId), $ttl, fn () => $this->resolve($businessId))
            : $this->resolve($businessId);

        return $this->memo[$businessId] = $resolved;
    }

    /** Codes that are on, for the nav-building views. */
    public function enabledCodes(int|Business|null $business = null): array
    {
        return array_keys(array_filter($this->all($business)));
    }

    /**
     * Backend gate for controllers: allow, or throw.
     *
     * @throws FeatureUnavailableException
     */
    public function authorize(string $code, int|Business|null $business = null): void
    {
        if ($this->enabled($code, $business)) {
            return;
        }

        throw new FeatureUnavailableException(
            $code,
            FeatureRegistry::all()[$code]['name'] ?? null,
        );
    }

    // ------------------------------------------------------------- resolution

    /**
     * The actual three-level merge. One query for the plan pivot, one for the
     * overrides — no per-feature lookups.
     *
     * @return array<string, bool>
     */
    protected function resolve(int $businessId): array
    {
        /** @var Business|null $business */
        $business = Business::query()
            ->with(['currentSubscription.plan.features'])
            ->find($businessId);

        $subscription = $business?->currentSubscription;

        // No entitlement at all → nothing is available.
        if ($subscription === null || $subscription->plan === null) {
            return $this->noneEnabled();
        }

        // Level 3: registry defaults, as recorded in the features table.
        $map = [];

        foreach (Feature::query()->get(['id', 'code', 'default_enabled', 'is_active']) as $feature) {
            // A feature switched off system-wide is off for everyone (#12).
            $map[$feature->code] = $feature->is_active && $feature->default_enabled;
        }

        // Level 2: the plan's pivot rows override the defaults.
        foreach ($subscription->plan->features as $feature) {
            if (! $feature->is_active) {
                continue;
            }

            $map[$feature->code] = (bool) $feature->pivot->is_enabled;
        }

        // Level 1: per-tenant operator overrides beat the plan.
        $overrides = BusinessFeatureOverride::query()
            ->where('business_id', $businessId)
            ->with('feature:id,code,is_active')
            ->get();

        foreach ($overrides as $override) {
            $feature = $override->feature;

            if ($feature === null || ! $feature->is_active) {
                continue;
            }

            $map[$feature->code] = (bool) $override->is_enabled;
        }

        // Guarantee every registry code is present, even if unseeded.
        foreach (FeatureRegistry::codes() as $code) {
            $map[$code] ??= false;
        }

        return $map;
    }

    /** @return array<string, bool> */
    protected function noneEnabled(): array
    {
        return array_fill_keys(FeatureRegistry::codes(), false);
    }

    // ------------------------------------------------------------------ cache

    /**
     * Drop the cached map. MUST be called after any change to a plan's features,
     * a tenant override, or a subscription — a stale entitlement is a security
     * bug, not just a display bug.
     */
    public function flush(int|Business|null $business = null): void
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return;
        }

        unset($this->memo[$businessId]);
        Cache::forget($this->cacheKey($businessId));
    }

    /**
     * Drop every business's map — for when a PLAN changed and we do not want to
     * hunt down its subscribers one by one.
     */
    public function flushAll(): void
    {
        $this->memo = [];

        Business::query()
            ->select('id')
            ->cursor()
            ->each(fn (Business $business) => Cache::forget($this->cacheKey($business->id)));
    }

    protected function cacheKey(int $businessId): string
    {
        return "features:business:{$businessId}";
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
