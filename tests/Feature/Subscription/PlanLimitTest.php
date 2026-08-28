<?php

namespace Tests\Feature\Subscription;

use App\Exceptions\LimitExceededException;
use App\Models\Business;
use App\Models\BusinessLimitOverride;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanLimitService;
use App\Support\LimitRegistry;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Numeric quotas: resolution, enforcement and meters (#8, #78, #79, #129, #187).
 *
 * THE THREE-VALUE CONVENTION is what most of this file exists to pin down. At
 * every level:
 *   row absent  → ask the level below (override → plan → registry)
 *   value NULL  → UNLIMITED
 *   value 0     → nothing allowed
 * so "unlimited" and "unset" are genuinely different states. Conflating them
 * either hands out infinite quota or locks a paying customer out of their own
 * plan, which is why several tests assert `null !== 0` explicitly.
 *
 * Enforcement is BACKEND (#79): a disabled button is decoration,
 * assertCanCreate() is the thing that actually stops the row being written.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // The resolver reads the limits table, so the registry must be synced.
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create();
    }

    protected function limits(): PlanLimitService
    {
        return app(PlanLimitService::class);
    }

    /** @param array<string, int|null> $limits code => value (null = unlimited) */
    protected function subscribeTo(array $limits, ?Business $business = null): Plan
    {
        $plan = Plan::factory()->monthly()->withLimits($limits)->create();

        Subscription::factory()
            ->forBusiness($business ?? $this->business)
            ->forPlan($plan)
            ->create();

        return $plan;
    }

    // -------------------------------------------------------- the fail-closed

    public function test_a_business_with_no_subscription_gets_a_zero_ceiling_everywhere(): void
    {
        $map = $this->limits()->all($this->business);

        foreach (LimitRegistry::codes() as $code) {
            $this->assertSame(0, $map[$code], "[{$code}] was not zero for a business with no plan.");
        }

        $this->assertFalse(
            $this->limits()->canCreate(LimitRegistry::PRODUCTS, 1, $this->business),
            'A business with no subscription was allowed to create something.'
        );
    }

    public function test_no_subscription_never_resolves_to_unlimited(): void
    {
        // The dangerous failure mode: a missing plan being read as "no ceiling".
        $this->assertFalse(
            $this->limits()->isUnlimited(LimitRegistry::PRODUCTS, $this->business),
            'No plan resolved to UNLIMITED — a free-loading tenant would have infinite quota.'
        );
        $this->assertNotNull($this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
    }

    // ------------------------------------------------------------ resolution

    public function test_the_plan_value_beats_the_registry_default(): void
    {
        $registryDefault = LimitRegistry::defaultFor(LimitRegistry::PRODUCTS);

        $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $this->assertSame(500, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
        $this->assertNotSame($registryDefault, 500, 'precondition: the plan value differs from the default');
    }

    public function test_a_limit_the_plan_says_nothing_about_falls_back_to_the_registry_default(): void
    {
        $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $this->assertSame(
            LimitRegistry::defaultFor(LimitRegistry::CUSTOMERS),
            $this->limits()->limit(LimitRegistry::CUSTOMERS, $this->business),
            'An unconfigured limit must inherit the registry default.'
        );
    }

    public function test_a_null_plan_value_means_unlimited(): void
    {
        $this->subscribeTo([LimitRegistry::PRODUCTS => null]);

        $this->assertNull(
            $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business),
            'NULL on the pivot must resolve to unlimited, not to 0.'
        );
        $this->assertTrue($this->limits()->isUnlimited(LimitRegistry::PRODUCTS, $this->business));
        $this->assertTrue($this->limits()->canCreate(LimitRegistry::PRODUCTS, 100_000, $this->business));
        $this->assertNull($this->limits()->remaining(LimitRegistry::PRODUCTS, $this->business));
    }

    public function test_a_zero_plan_value_means_nothing_allowed_not_unset(): void
    {
        $this->subscribeTo([LimitRegistry::BRANCHES => 0]);

        $this->assertSame(0, $this->limits()->limit(LimitRegistry::BRANCHES, $this->business));
        $this->assertFalse($this->limits()->isUnlimited(LimitRegistry::BRANCHES, $this->business));
        $this->assertFalse(
            $this->limits()->canCreate(LimitRegistry::BRANCHES, 1, $this->business),
            'A zero ceiling was treated as "unset" and let a create through.'
        );
    }

    public function test_an_override_beats_the_plan_in_both_directions(): void
    {
        $this->subscribeTo([
            LimitRegistry::PRODUCTS => 500,
            LimitRegistry::BRANCHES => 1,
        ]);

        $this->override(LimitRegistry::PRODUCTS, 20);   // tightened
        $this->override(LimitRegistry::BRANCHES, null); // loosened to unlimited

        $this->limits()->flush($this->business);

        $this->assertSame(20, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
        $this->assertNull($this->limits()->limit(LimitRegistry::BRANCHES, $this->business));
    }

    public function test_an_override_of_zero_is_honoured_over_a_generous_plan(): void
    {
        $this->subscribeTo([LimitRegistry::SMS_PER_MONTH => 1000]);

        $this->override(LimitRegistry::SMS_PER_MONTH, 0);
        $this->limits()->flush($this->business);

        $this->assertSame(
            0,
            $this->limits()->limit(LimitRegistry::SMS_PER_MONTH, $this->business),
            'A deliberate 0 override was swallowed and the plan value came back.'
        );
    }

    public function test_an_override_cannot_grant_quota_to_a_business_with_no_subscription(): void
    {
        $this->override(LimitRegistry::PRODUCTS, 9999);

        $this->assertSame(
            0,
            $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business),
            'An override bypassed the "no subscription means nothing" rule.'
        );
    }

    public function test_a_deactivated_limit_is_zero_for_everyone(): void
    {
        $this->subscribeTo([LimitRegistry::WAREHOUSES => 50]);

        Limit::query()->where('code', LimitRegistry::WAREHOUSES)->update(['is_active' => false]);
        $this->limits()->flush($this->business);

        $this->assertSame(
            0,
            $this->limits()->limit(LimitRegistry::WAREHOUSES, $this->business),
            'A limit switched off system-wide was still granted by a plan.'
        );
    }

    public function test_one_businesss_quota_does_not_leak_into_another(): void
    {
        $other = Business::factory()->create();

        $plan = $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);
        Subscription::factory()->forBusiness($other)->forPlan($plan)->create();

        $this->override(LimitRegistry::PRODUCTS, 5);
        $this->limits()->flush($this->business);

        $this->assertSame(5, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
        $this->assertSame(
            500,
            $this->limits()->limit(LimitRegistry::PRODUCTS, $other),
            'A limit override leaked across tenants — ISOLATION BROKEN.'
        );
    }

    public function test_an_unknown_code_resolves_to_zero_rather_than_unlimited(): void
    {
        $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $this->assertSame(
            0,
            $this->limits()->limit('limits.not_a_real_code', $this->business),
            'An unknown limit code must fail closed.'
        );
    }

    // ------------------------------------------------------------ live usage

    public function test_usage_is_counted_live_from_the_tenants_own_rows(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 5]);

        User::factory()->for($this->business)->count(3)->create();

        // Another tenant's staff must not count against this quota.
        $other = Business::factory()->create();
        User::factory()->for($other)->count(4)->create();

        $this->assertSame(3, $this->limits()->usage(LimitRegistry::EMPLOYEES, $this->business));
        $this->assertSame(2, $this->limits()->remaining(LimitRegistry::EMPLOYEES, $this->business));
    }

    public function test_usage_is_never_cached_even_when_ceilings_are(): void
    {
        // A stale ceiling is harmless for an hour. A stale COUNT would let a
        // tenant blow past its quota by hammering the form.
        config()->set('subscription.cache_ttl', 3600);

        $this->subscribeTo([LimitRegistry::EMPLOYEES => 5]);

        $this->assertSame(0, $this->limits()->usage(LimitRegistry::EMPLOYEES, $this->business));

        User::factory()->for($this->business)->count(2)->create();

        $this->assertSame(
            2,
            $this->limits()->usage(LimitRegistry::EMPLOYEES, $this->business),
            'Usage was served from a cache — the quota could be exceeded by racing the form.'
        );
    }

    public function test_a_code_with_no_registered_counter_reports_zero_usage(): void
    {
        // Later phases register their own counters; until then the ceiling still
        // applies, usage is simply unknown-as-zero. That is safe, not a bypass.
        $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $this->assertFalse($this->limits()->hasUsageResolver(LimitRegistry::PRODUCTS));
        $this->assertSame(0, $this->limits()->usage(LimitRegistry::PRODUCTS, $this->business));
        $this->assertSame(500, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
    }

    public function test_a_registered_resolver_is_used(): void
    {
        $this->subscribeTo([LimitRegistry::PRODUCTS => 10]);

        $service = $this->limits();
        $service->registerUsageResolver(LimitRegistry::PRODUCTS, fn (int $businessId): int => 9);

        $this->assertSame(9, $service->usage(LimitRegistry::PRODUCTS, $this->business));
        $this->assertTrue($service->canCreate(LimitRegistry::PRODUCTS, 1, $this->business));
        $this->assertFalse($service->canCreate(LimitRegistry::PRODUCTS, 2, $this->business));
    }

    // ----------------------------------------------------------- enforcement

    public function test_can_create_refuses_the_row_that_would_cross_the_ceiling(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 3]);

        User::factory()->for($this->business)->count(2)->create();

        $service = $this->limits();

        $this->assertTrue($service->canCreate(LimitRegistry::EMPLOYEES, 1, $this->business), 'The 3rd must be allowed.');
        $this->assertFalse($service->canCreate(LimitRegistry::EMPLOYEES, 2, $this->business), 'The 4th must not.');
    }

    public function test_the_ceiling_is_inclusive(): void
    {
        // A quota of 3 means three rows may exist, not two. Off-by-one here is
        // the difference between "500 products" and "499 products".
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 3]);

        User::factory()->for($this->business)->count(3)->create();

        $this->assertSame(3, $this->limits()->usage(LimitRegistry::EMPLOYEES, $this->business));
        $this->assertSame(0, $this->limits()->remaining(LimitRegistry::EMPLOYEES, $this->business));
        $this->assertFalse($this->limits()->canCreate(LimitRegistry::EMPLOYEES, 1, $this->business));
    }

    public function test_assert_can_create_throws_with_the_numbers_the_ui_needs(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 2]);

        User::factory()->for($this->business)->count(2)->create();

        try {
            $this->limits()->assertCanCreate(LimitRegistry::EMPLOYEES, 1, $this->business);
            $this->fail('assertCanCreate() did not throw once the quota was full — #79 enforcement missing.');
        } catch (LimitExceededException $e) {
            $this->assertSame(LimitRegistry::EMPLOYEES, $e->limitCode);
            $this->assertSame(2, $e->limit);
            $this->assertSame(2, $e->usage);
            $this->assertSame(LimitRegistry::name(LimitRegistry::EMPLOYEES), $e->context()['limit_name']);
        }
    }

    public function test_assert_can_create_is_silent_when_there_is_room(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 10]);

        $this->limits()->assertCanCreate(LimitRegistry::EMPLOYEES, 1, $this->business);

        $this->addToAssertionCount(1);
    }

    public function test_unlimited_never_throws(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => null]);

        User::factory()->for($this->business)->count(20)->create();

        $this->limits()->assertCanCreate(LimitRegistry::EMPLOYEES, 500, $this->business);

        $this->addToAssertionCount(1);
    }

    public function test_remaining_is_clamped_at_zero_when_usage_somehow_exceeds_the_cap(): void
    {
        // Happens legitimately after a downgrade: the tenant already has 5 users
        // and the new plan allows 2. Their data is kept (#83), they simply cannot
        // add more — so "remaining" must be 0, never negative.
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 2]);

        User::factory()->for($this->business)->count(5)->create();

        $this->assertSame(0, $this->limits()->remaining(LimitRegistry::EMPLOYEES, $this->business));
        $this->assertFalse($this->limits()->canCreate(LimitRegistry::EMPLOYEES, 1, $this->business));
    }

    // ---------------------------------------------------------------- meters

    public function test_the_meter_reports_the_numbers_a_progress_bar_needs(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 4]);

        User::factory()->for($this->business)->count(1)->create();

        $meter = $this->limits()->meter(LimitRegistry::EMPLOYEES, $this->business);

        $this->assertSame(1, $meter['usage']);
        $this->assertSame(4, $meter['limit']);
        $this->assertSame(25, $meter['percent']);
        $this->assertSame('1 / 4', $meter['label']);
        $this->assertFalse($meter['unlimited']);
        $this->assertFalse($meter['exhausted']);
        $this->assertFalse($meter['nearly_exhausted']);
        $this->assertSame('bg-brand-500', $meter['bar_class']);
    }

    public function test_the_meter_warns_at_eighty_percent_and_shouts_when_full(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => 5]);

        User::factory()->for($this->business)->count(4)->create();

        $warning = $this->limits()->meter(LimitRegistry::EMPLOYEES, $this->business);
        $this->assertSame(80, $warning['percent']);
        $this->assertTrue($warning['nearly_exhausted']);
        $this->assertFalse($warning['exhausted']);
        $this->assertSame('bg-amber-500', $warning['bar_class']);

        User::factory()->for($this->business)->create();

        $full = $this->limits()->meter(LimitRegistry::EMPLOYEES, $this->business);
        $this->assertSame(100, $full['percent']);
        $this->assertTrue($full['exhausted']);
        $this->assertFalse($full['nearly_exhausted'], 'Full is not "nearly full" — the two states are exclusive.');
        $this->assertSame('bg-rose-500', $full['bar_class']);
    }

    public function test_an_unlimited_meter_shows_no_percentage(): void
    {
        $this->subscribeTo([LimitRegistry::EMPLOYEES => null]);

        User::factory()->for($this->business)->count(7)->create();

        $meter = $this->limits()->meter(LimitRegistry::EMPLOYEES, $this->business);

        $this->assertTrue($meter['unlimited']);
        $this->assertSame(0, $meter['percent'], 'An unlimited bar must not read as full.');
        $this->assertSame('7 / Unlimited', $meter['label']);
        $this->assertNull($meter['remaining']);
        $this->assertFalse($meter['exhausted']);
    }

    public function test_a_zero_ceiling_meter_reads_as_full(): void
    {
        $this->subscribeTo([LimitRegistry::BRANCHES => 0]);

        $meter = $this->limits()->meter(LimitRegistry::BRANCHES, $this->business);

        $this->assertSame(100, $meter['percent'], 'A 0/0 quota has no room, so the bar is full.');
        $this->assertTrue($meter['exhausted']);
    }

    public function test_meters_covers_every_limit_and_carries_the_monthly_flag(): void
    {
        $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $meters = $this->limits()->meters(null, $this->business);

        $this->assertSame(LimitRegistry::codes(), array_keys($meters));
        $this->assertTrue($meters[LimitRegistry::INVOICES_PER_MONTH]['is_monthly']);
        $this->assertFalse($meters[LimitRegistry::PRODUCTS]['is_monthly']);
    }

    // ----------------------------------------------------------------- cache

    public function test_ceilings_are_cached_and_the_flush_invalidates_them(): void
    {
        config()->set('subscription.cache_ttl', 3600);

        $plan = $this->subscribeTo([LimitRegistry::PRODUCTS => 500]);

        $this->assertSame(500, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));

        $limit = Limit::query()->where('code', LimitRegistry::PRODUCTS)->firstOrFail();
        $plan->limits()->updateExistingPivot($limit->id, ['value' => 50]);

        $this->assertSame(
            500,
            $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business),
            'precondition: the cached ceiling is being served'
        );

        $this->limits()->flush($this->business);

        $this->assertSame(50, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));
    }

    public function test_a_downgrade_through_the_service_tightens_the_ceiling_immediately(): void
    {
        config()->set('subscription.cache_ttl', 3600);

        $this->subscribeTo([LimitRegistry::PRODUCTS => 5000]);

        $this->assertSame(5000, $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business));

        $cheaper = Plan::factory()->monthly(9.00)->withLimits([LimitRegistry::PRODUCTS => 50])->create();

        app(\App\Services\SubscriptionService::class)
            ->changePlan($this->business, $cheaper, \App\Enums\BillingCycle::Monthly);

        $this->assertSame(
            50,
            $this->limits()->limit(LimitRegistry::PRODUCTS, $this->business),
            'A downgrade left the old ceiling cached — the tenant keeps quota they no longer pay for.'
        );
    }

    // --------------------------------------------------------------- helpers

    protected function override(string $code, ?int $value): BusinessLimitOverride
    {
        $limit = Limit::query()->where('code', $code)->firstOrFail();

        return BusinessLimitOverride::query()->create([
            'business_id' => $this->business->id,
            'limit_id' => $limit->id,
            'value' => $value,
            'reason' => 'Test override',
        ]);
    }
}
