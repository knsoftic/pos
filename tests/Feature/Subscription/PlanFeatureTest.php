<?php

namespace Tests\Feature\Subscription;

use App\Enums\BillingCycle;
use App\Exceptions\FeatureUnavailableException;
use App\Http\Middleware\CheckFeature;
use App\Models\Business;
use App\Models\BusinessFeatureOverride;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\SubscriptionService;
use App\Support\FeatureRegistry;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Dynamic feature entitlements (#9, #10, #80, #125, #128, #130, #187).
 *
 * The resolution order under test — first level with a ROW wins:
 *   1. business_feature_overrides   (this tenant specifically)
 *   2. plan_feature                (the subscribed plan)
 *   3. features.default_enabled    (an unconfigured plan)
 *
 * Two rules matter more than the rest:
 *   - NO SUBSCRIPTION → EVERYTHING OFF. Fail closed, always.
 *   - Hiding a nav link is decoration; the middleware is the enforcement. A
 *     hand-written request to the same URL must still be refused (#80).
 */
class PlanFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // The resolver reads the features table, so the registry must be synced.
        $this->seed(FeatureSeeder::class);

        $this->business = Business::factory()->create();
    }

    protected function features(): FeatureService
    {
        // Resolved fresh each time so the per-request memo never masks a change
        // that should have been invalidated.
        return app(FeatureService::class);
    }

    /** @param array<string, bool> $features code => enabled */
    protected function subscribeTo(array $features, ?Business $business = null): Plan
    {
        $plan = Plan::factory()->monthly()->create();

        foreach ($features as $code => $enabled) {
            $feature = Feature::query()->where('code', $code)->firstOrFail();
            $plan->features()->attach($feature->id, ['is_enabled' => $enabled]);
        }

        Subscription::factory()
            ->forBusiness($business ?? $this->business)
            ->forPlan($plan)
            ->create();

        return $plan;
    }

    // --------------------------------------------------------- the fail-closed

    public function test_a_business_with_no_subscription_has_no_features_at_all(): void
    {
        $map = $this->features()->all($this->business);

        $this->assertNotEmpty($map, 'The map must still list every registry code.');
        $this->assertSame(
            [],
            array_keys(array_filter($map)),
            'A business with no plan was granted features — the paywall leaks.'
        );
        $this->assertFalse($this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business));
    }

    public function test_features_are_off_even_when_the_registry_default_is_on_if_there_is_no_plan(): void
    {
        // The registry default is the fallback for an UNCONFIGURED PLAN, not for
        // having no plan — otherwise a free-loading tenant would inherit whatever
        // the operator marked default_enabled.
        $onByDefault = Feature::query()->where('default_enabled', true)->firstOrFail();

        $this->assertFalse(
            $this->features()->enabled($onByDefault->code, $this->business),
            "[{$onByDefault->code}] leaked to a business with no subscription."
        );
    }

    public function test_a_superseded_subscription_grants_nothing(): void
    {
        $plan = Plan::factory()->monthly()->create();
        $feature = Feature::query()->where('code', FeatureRegistry::POS_TERMINAL)->firstOrFail();
        $plan->features()->attach($feature->id, ['is_enabled' => true]);

        Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($plan)
            ->superseded()
            ->create();

        $this->assertFalse(
            $this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business),
            'A replaced subscription row was still granting entitlements.'
        );
    }

    // ------------------------------------------------------------- the plan

    public function test_the_plan_pivot_decides_what_is_on(): void
    {
        $this->subscribeTo([
            FeatureRegistry::POS_TERMINAL => true,
            FeatureRegistry::BRANCHES_MULTI_BRANCH => false,
        ]);

        $service = $this->features();

        $this->assertTrue($service->enabled(FeatureRegistry::POS_TERMINAL, $this->business));
        $this->assertFalse($service->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business));
        $this->assertTrue($service->disabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business));
    }

    public function test_the_plan_pivot_can_switch_off_a_feature_that_is_on_by_default(): void
    {
        $onByDefault = Feature::query()->where('default_enabled', true)->firstOrFail();

        $this->subscribeTo([$onByDefault->code => false]);

        $this->assertFalse(
            $this->features()->enabled($onByDefault->code, $this->business),
            'The plan must be able to withhold a default-on feature.'
        );
    }

    public function test_an_unconfigured_feature_falls_back_to_the_registry_default(): void
    {
        // Plan says nothing about this code at all.
        $this->subscribeTo([FeatureRegistry::POS_TERMINAL => true]);

        $default = Feature::query()->where('code', FeatureRegistry::REPORTS_BASIC)->firstOrFail();

        $this->assertSame(
            (bool) $default->default_enabled,
            $this->features()->enabled(FeatureRegistry::REPORTS_BASIC, $this->business),
            'An unconfigured feature must inherit the registry default, not guess.'
        );
    }

    public function test_a_feature_deactivated_system_wide_is_off_for_everyone(): void
    {
        $this->subscribeTo([FeatureRegistry::POS_TERMINAL => true]);

        $this->assertTrue($this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business));

        // The operator kills the flag globally (#12) — a paid plan cannot revive it.
        Feature::query()->where('code', FeatureRegistry::POS_TERMINAL)->update(['is_active' => false]);
        $this->features()->flush($this->business);

        $this->assertFalse(
            $this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business),
            'A globally disabled feature was still granted by a plan.'
        );
    }

    // -------------------------------------------------------- the override

    public function test_a_business_override_beats_the_plan_in_both_directions(): void
    {
        $this->subscribeTo([
            FeatureRegistry::POS_TERMINAL => true,
            FeatureRegistry::BRANCHES_MULTI_BRANCH => false,
        ]);

        $off = Feature::query()->where('code', FeatureRegistry::POS_TERMINAL)->firstOrFail();
        $on = Feature::query()->where('code', FeatureRegistry::BRANCHES_MULTI_BRANCH)->firstOrFail();

        BusinessFeatureOverride::query()->create([
            'business_id' => $this->business->id,
            'feature_id' => $off->id,
            'is_enabled' => false,
            'reason' => 'Abuse',
        ]);

        BusinessFeatureOverride::query()->create([
            'business_id' => $this->business->id,
            'feature_id' => $on->id,
            'is_enabled' => true,
            'reason' => 'Agreed exception',
        ]);

        $this->features()->flush($this->business);
        $service = $this->features();

        $this->assertFalse($service->enabled(FeatureRegistry::POS_TERMINAL, $this->business), 'Override could not revoke.');
        $this->assertTrue($service->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business), 'Override could not grant.');
    }

    public function test_an_override_cannot_grant_a_feature_to_a_business_with_no_subscription(): void
    {
        // Overrides sit above the plan, but there is no plan to sit above. The
        // subscription check comes first and refuses before overrides are read.
        $feature = Feature::query()->where('code', FeatureRegistry::POS_TERMINAL)->firstOrFail();

        BusinessFeatureOverride::query()->create([
            'business_id' => $this->business->id,
            'feature_id' => $feature->id,
            'is_enabled' => true,
        ]);

        $this->assertFalse(
            $this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business),
            'An override bypassed the "no subscription means nothing" rule.'
        );
    }

    public function test_one_businesss_override_does_not_affect_another(): void
    {
        $other = Business::factory()->create();

        $plan = $this->subscribeTo([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);

        Subscription::factory()->forBusiness($other)->forPlan($plan)->create();

        $feature = Feature::query()->where('code', FeatureRegistry::BRANCHES_MULTI_BRANCH)->firstOrFail();

        BusinessFeatureOverride::query()->create([
            'business_id' => $this->business->id,
            'feature_id' => $feature->id,
            'is_enabled' => true,
        ]);

        $service = $this->features();

        $this->assertTrue($service->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business));
        $this->assertFalse(
            $service->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $other),
            'An override leaked across tenants — ISOLATION BROKEN.'
        );
    }

    // ------------------------------------------------------------ the cache

    public function test_the_resolved_map_is_cached_and_the_flush_invalidates_it(): void
    {
        config()->set('subscription.cache_ttl', 3600);

        $plan = $this->subscribeTo([FeatureRegistry::POS_TERMINAL => true]);

        $this->assertTrue($this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business));

        // Change the plan behind the service's back.
        $feature = Feature::query()->where('code', FeatureRegistry::POS_TERMINAL)->firstOrFail();
        $plan->features()->updateExistingPivot($feature->id, ['is_enabled' => false]);

        $this->assertTrue(
            $this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business),
            'precondition: the cached map is being served'
        );

        $this->features()->flush($this->business);

        $this->assertFalse(
            $this->features()->enabled(FeatureRegistry::POS_TERMINAL, $this->business),
            'flush() did not invalidate the cached entitlement map — a stale entitlement is a security bug.'
        );
    }

    public function test_a_downgrade_through_the_service_flushes_the_cache_by_itself(): void
    {
        config()->set('subscription.cache_ttl', 3600);

        $this->subscribeTo([FeatureRegistry::BRANCHES_MULTI_BRANCH => true]);

        $this->assertTrue($this->features()->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business));

        // A cheaper plan without the flag.
        $cheaper = Plan::factory()->monthly(9.00)->create();
        $feature = Feature::query()->where('code', FeatureRegistry::BRANCHES_MULTI_BRANCH)->firstOrFail();
        $cheaper->features()->attach($feature->id, ['is_enabled' => false]);

        app(SubscriptionService::class)
            ->changePlan($this->business, $cheaper, BillingCycle::Monthly);

        $this->assertFalse(
            $this->features()->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business),
            'A downgrade left the old entitlements cached — the tenant keeps a feature they no longer pay for.'
        );
    }

    // ------------------------------------------------------------ helpers

    public function test_all_of_and_any_of_combine_codes_correctly(): void
    {
        $this->subscribeTo([
            FeatureRegistry::POS_TERMINAL => true,
            FeatureRegistry::SALES_INVOICING => true,
            FeatureRegistry::BRANCHES_MULTI_BRANCH => false,
        ]);

        $service = $this->features();
        $both = [FeatureRegistry::POS_TERMINAL, FeatureRegistry::SALES_INVOICING];
        $mixed = [FeatureRegistry::POS_TERMINAL, FeatureRegistry::BRANCHES_MULTI_BRANCH];

        $this->assertTrue($service->allOf($both, $this->business));
        $this->assertFalse($service->allOf($mixed, $this->business));
        $this->assertTrue($service->anyOf($mixed, $this->business));
        $this->assertFalse($service->anyOf([FeatureRegistry::BRANCHES_MULTI_BRANCH], $this->business));
    }

    public function test_enabled_codes_lists_only_what_is_on(): void
    {
        $this->subscribeTo([
            FeatureRegistry::POS_TERMINAL => true,
            FeatureRegistry::BRANCHES_MULTI_BRANCH => false,
        ]);

        $codes = $this->features()->enabledCodes($this->business);

        $this->assertContains(FeatureRegistry::POS_TERMINAL, $codes);
        $this->assertNotContains(FeatureRegistry::BRANCHES_MULTI_BRANCH, $codes);
    }

    public function test_authorize_throws_for_a_feature_that_is_not_included(): void
    {
        $this->subscribeTo([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);

        $this->expectException(FeatureUnavailableException::class);

        $this->features()->authorize(FeatureRegistry::BRANCHES_MULTI_BRANCH, $this->business);
    }

    public function test_the_map_covers_every_registry_code_so_callers_can_trust_the_key(): void
    {
        $this->subscribeTo([FeatureRegistry::POS_TERMINAL => true]);

        $map = $this->features()->all($this->business);

        foreach (FeatureRegistry::codes() as $code) {
            $this->assertArrayHasKey($code, $map, "[{$code}] is missing from the resolved map.");
        }
    }

    // --------------------------------------------------- middleware (#80/#130)

    public function test_the_feature_middleware_refuses_a_request_for_a_feature_not_on_the_plan(): void
    {
        // The whole point of #80: hiding the link is not enforcement. This proves
        // a hand-written request to the same URL is still refused.
        //
        // A browser is redirected to billing (where the problem can be fixed);
        // an API caller gets 403 JSON. Both are refusals — what must never happen
        // is the route body running.
        Route::middleware(['web', 'auth:web', 'tenant', 'feature:'.FeatureRegistry::BRANCHES_MULTI_BRANCH])
            ->get('/__test/branches', fn () => 'reached')
            ->name('test.branches');

        $this->subscribeTo([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);

        $user = User::factory()->for($this->business)->create();

        $this->actingAs($user, 'web')
            ->get('/__test/branches')
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('feature_unavailable')
            ->assertDontSee('reached');
    }

    public function test_the_feature_middleware_answers_an_api_caller_with_403(): void
    {
        Route::middleware(['web', 'auth:web', 'tenant', 'feature:'.FeatureRegistry::BRANCHES_MULTI_BRANCH])
            ->get('/__test/branches-json', fn () => 'reached');

        $this->subscribeTo([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);

        $user = User::factory()->for($this->business)->create();

        $this->actingAs($user, 'web')
            ->getJson('/__test/branches-json')
            ->assertForbidden()
            ->assertJsonPath('error', 'feature_unavailable')
            ->assertJsonPath('context.feature_code', FeatureRegistry::BRANCHES_MULTI_BRANCH);
    }

    public function test_the_feature_middleware_lets_an_entitled_request_through(): void
    {
        Route::middleware(['web', 'auth:web', 'tenant', 'feature:'.FeatureRegistry::POS_TERMINAL])
            ->get('/__test/pos', fn () => 'reached')
            ->name('test.pos');

        $this->subscribeTo([FeatureRegistry::POS_TERMINAL => true]);

        $user = User::factory()->for($this->business)->create();

        $this->actingAs($user, 'web')
            ->get('/__test/pos')
            ->assertOk()
            ->assertSee('reached');
    }

    public function test_the_feature_middleware_ands_multiple_codes(): void
    {
        Route::middleware([
            'web', 'auth:web', 'tenant',
            'feature:'.FeatureRegistry::POS_TERMINAL.','.FeatureRegistry::BRANCHES_MULTI_BRANCH,
        ])->get('/__test/both', fn () => 'reached');

        $this->subscribeTo([
            FeatureRegistry::POS_TERMINAL => true,
            FeatureRegistry::BRANCHES_MULTI_BRANCH => false,
        ]);

        $user = User::factory()->for($this->business)->create();

        // Entitled to POS, not to branches — the AND must fail on the second code.
        $this->actingAs($user, 'web')
            ->getJson('/__test/both')
            ->assertForbidden()
            ->assertJsonPath('context.feature_code', FeatureRegistry::BRANCHES_MULTI_BRANCH);
    }

    public function test_an_unknown_feature_code_in_a_route_gate_fails_loudly(): void
    {
        // A typo must not silently open a door. The middleware throws instead.
        $this->expectException(\InvalidArgumentException::class);

        app(CheckFeature::class)->handle(
            request(),
            fn ($request) => response('reached'),
            'pos.does_not_exist',
        );
    }
}
