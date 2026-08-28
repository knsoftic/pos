<?php

namespace App\Services;

use App\Exceptions\LimitExceededException;
use App\Models\Business;
use App\Models\BusinessLimitOverride;
use App\Models\Limit;
use App\Models\User;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves and enforces numeric quotas (#78, #187).
 *
 * RESOLUTION ORDER for the ceiling — first level with a ROW wins:
 *   1. `business_limit_overrides` — this tenant specifically (#10)
 *   2. `plan_limit` — the subscribed plan
 *   3. `limits.default_value` / `default_unlimited` — unconfigured fallback
 *
 * At every level the stored value means the same thing:
 *   NULL → unlimited        0 → nothing allowed        n → ceiling of n
 * and "no row" means "ask the next level down", which is why NULL-as-unlimited
 * is unambiguous: absence and NULL are different states.
 *
 * NO CURRENT SUBSCRIPTION → EVERY LIMIT IS 0. Same fail-closed rule as
 * {@see FeatureService}.
 *
 * CEILINGS ARE CACHED, USAGE IS NEVER CACHED. A stale ceiling is harmless for
 * a few minutes; a stale count would let a tenant create past its quota by
 * hammering the form. Usage is always counted live.
 *
 * USAGE RESOLVERS: this service does not know how to count products or invoices
 * — those tables arrive in later phases. Each phase registers a counter with
 * {@see self::registerUsageResolver()} (see AppServiceProvider), and an
 * unregistered code simply reports 0 usage, which is safe: the ceiling still
 * applies the moment a counter exists.
 */
class PlanLimitService
{
    /** @var array<string, callable(int): int> code => fn(businessId) => usage */
    protected array $usageResolvers = [];

    /** Per-request memo of resolved ceilings. */
    protected array $memo = [];

    public function __construct(protected TenantContext $tenant)
    {
        $this->registerDefaultResolvers();
    }

    // ------------------------------------------------------------- the ceiling

    /**
     * The resolved ceiling. NULL means unlimited — callers MUST handle null
     * rather than casting it, or unlimited silently becomes zero.
     */
    public function limit(string $code, int|Business|null $business = null): ?int
    {
        $map = $this->all($business);

        // array_key_exists, not ??: null is a meaningful value here.
        return array_key_exists($code, $map) ? $map[$code] : 0;
    }

    public function isUnlimited(string $code, int|Business|null $business = null): bool
    {
        return $this->limit($code, $business) === null;
    }

    /**
     * All ceilings for a business: code => int|null.
     *
     * @return array<string, int|null>
     */
    public function all(int|Business|null $business = null): array
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return array_fill_keys(LimitRegistry::codes(), 0);
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

    // ---------------------------------------------------------------- the usage

    /** Live count of what the business is currently consuming. */
    public function usage(string $code, int|Business|null $business = null): int
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null || ! isset($this->usageResolvers[$code])) {
            return 0;
        }

        return max(0, (int) ($this->usageResolvers[$code])($businessId));
    }

    /** NULL when unlimited. Never negative, even if usage somehow exceeds the cap. */
    public function remaining(string $code, int|Business|null $business = null): ?int
    {
        $limit = $this->limit($code, $business);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->usage($code, $business));
    }

    // ------------------------------------------------------------- enforcement

    /** Is there room for $quantity more? */
    public function canCreate(string $code, int $quantity = 1, int|Business|null $business = null): bool
    {
        $limit = $this->limit($code, $business);

        if ($limit === null) {
            return true;
        }

        return ($this->usage($code, $business) + $quantity) <= $limit;
    }

    /**
     * The guard controllers call before writing. Throws rather than returning
     * false so a forgotten check cannot silently pass.
     *
     * @throws LimitExceededException
     */
    public function assertCanCreate(string $code, int $quantity = 1, int|Business|null $business = null): void
    {
        if ($this->canCreate($code, $quantity, $business)) {
            return;
        }

        throw new LimitExceededException(
            $code,
            (int) $this->limit($code, $business),
            $this->usage($code, $business),
        );
    }

    // -------------------------------------------------------------------- UI

    /**
     * Everything a usage meter needs: "350 / 500", 70%, and whether to shout.
     *
     * @return array{
     *     code: string, name: string, unit: string, usage: int, limit: int|null,
     *     unlimited: bool, remaining: int|null, percent: int, label: string,
     *     is_monthly: bool, exhausted: bool, nearly_exhausted: bool, bar_class: string
     * }
     */
    public function meter(string $code, int|Business|null $business = null): array
    {
        $limit = $this->limit($code, $business);
        $usage = $this->usage($code, $business);
        $unlimited = $limit === null;

        // A zero ceiling has no meaningful percentage — treat it as full.
        $percent = match (true) {
            $unlimited => 0,
            $limit === 0 => 100,
            default => (int) min(100, round($usage / $limit * 100)),
        };

        $exhausted = ! $unlimited && $usage >= $limit;

        return [
            'code' => $code,
            'name' => LimitRegistry::name($code),
            'unit' => LimitRegistry::unit($code),
            'usage' => $usage,
            'limit' => $limit,
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : max(0, $limit - $usage),
            'percent' => $percent,
            'label' => $unlimited
                ? number_format($usage).' / Unlimited'
                : number_format($usage).' / '.number_format($limit),
            'is_monthly' => LimitRegistry::isMonthly($code),
            'exhausted' => $exhausted,
            'nearly_exhausted' => ! $unlimited && ! $exhausted && $percent >= 80,
            'bar_class' => match (true) {
                $unlimited => 'bg-brand-500',
                $exhausted => 'bg-rose-500',
                $percent >= 80 => 'bg-amber-500',
                default => 'bg-brand-500',
            },
        ];
    }

    /**
     * Meters for every limit, or a chosen subset, for the billing page.
     *
     * @param  list<string>|null  $codes
     * @return array<string, array<string, mixed>>
     */
    public function meters(?array $codes = null, int|Business|null $business = null): array
    {
        $codes ??= LimitRegistry::codes();
        $meters = [];

        foreach ($codes as $code) {
            $meters[$code] = $this->meter($code, $business);
        }

        return $meters;
    }

    // ------------------------------------------------------------- resolution

    /** @return array<string, int|null> */
    protected function resolve(int $businessId): array
    {
        /** @var Business|null $business */
        $business = Business::query()
            ->with(['currentSubscription.plan.limits'])
            ->find($businessId);

        $subscription = $business?->currentSubscription;

        if ($subscription === null || $subscription->plan === null) {
            return array_fill_keys(LimitRegistry::codes(), 0);
        }

        // Level 3 — registry defaults as stored in the limits table.
        $map = [];

        foreach (Limit::query()->get(['id', 'code', 'default_value', 'default_unlimited', 'is_active']) as $limit) {
            $map[$limit->code] = $limit->is_active ? $limit->defaultValue() : 0;
        }

        // Level 2 — the plan's pivot value (NULL there = unlimited).
        foreach ($subscription->plan->limits as $limit) {
            if (! $limit->is_active) {
                continue;
            }

            $value = $limit->pivot->value;
            $map[$limit->code] = $value === null ? null : (int) $value;
        }

        // Level 1 — per-tenant override beats the plan.
        $overrides = BusinessLimitOverride::query()
            ->where('business_id', $businessId)
            ->with('limit:id,code,is_active')
            ->get();

        foreach ($overrides as $override) {
            $limit = $override->limit;

            if ($limit === null || ! $limit->is_active) {
                continue;
            }

            $map[$limit->code] = $override->value === null ? null : (int) $override->value;
        }

        foreach (LimitRegistry::codes() as $code) {
            if (! array_key_exists($code, $map)) {
                $map[$code] = LimitRegistry::defaultFor($code);
            }
        }

        return $map;
    }

    // -------------------------------------------------------- usage resolvers

    /**
     * Teach the service how to count one quota. Later phases call this from a
     * service provider as their tables land.
     *
     * @param  callable(int): int  $resolver  fn(businessId) => current usage
     */
    public function registerUsageResolver(string $code, callable $resolver): void
    {
        $this->usageResolvers[$code] = $resolver;
    }

    public function hasUsageResolver(string $code): bool
    {
        return isset($this->usageResolvers[$code]);
    }

    /**
     * What we can already count in Phase 2. Everything else is registered by the
     * phase that creates the table, so this list grows instead of this class
     * accumulating knowledge of tables it cannot see.
     *
     * Trashed users are excluded — an archived employee should not keep occupying
     * a seat (#104).
     */
    protected function registerDefaultResolvers(): void
    {
        $this->registerUsageResolver(
            LimitRegistry::EMPLOYEES,
            fn (int $businessId): int => User::query()->forBusiness($businessId)->count(),
        );
    }

    // ------------------------------------------------------------------ cache

    public function flush(int|Business|null $business = null): void
    {
        $businessId = $this->resolveBusinessId($business);

        if ($businessId === null) {
            return;
        }

        unset($this->memo[$businessId]);
        Cache::forget($this->cacheKey($businessId));
    }

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
        return "limits:business:{$businessId}";
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
