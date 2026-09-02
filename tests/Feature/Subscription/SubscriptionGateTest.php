<?php

namespace Tests\Feature\Subscription;

use App\Enums\ExpiryBehavior;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\CheckSubscription;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The paywall itself — layer 1 of the three-layer check (#11, #79, #127, #187).
 *
 * TenantIsolationTest deliberately gives its fixtures a live subscription so it
 * can test isolation; this file is the other half of that bargain. Here the
 * subscription is the variable and the question is always the same: can a tenant
 * who is not paid up reach a business-facing route?
 *
 * Three things are being pinned down:
 *   1. ACCESS IS DERIVED FROM DATES. A stale `status` column must not get a
 *      lapsed tenant through, exactly as at model level.
 *   2. WHAT LAPSING COSTS YOU IS OPERATOR POLICY (#190), not a hardcoded
 *      decision — lock / read_only / pos_off each get their own test.
 *   3. BILLING AND LOGOUT ARE ALWAYS REACHABLE. Blocking those would trap the
 *      tenant in a loop with no way to pay and no way out.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['name' => 'Gate Test Shop']);
        $this->user = User::factory()->for($this->business)->create();
        $this->plan = Plan::factory()->monthly()->create();

        $this->registerTestRoutes();
    }

    /**
     * Stand-ins for routes later phases will add: one read, one write, and one
     * POS screen. They carry the real `tenant.app` group, so whatever the gate
     * does to them is what it will do to the real thing.
     */
    protected function registerTestRoutes(): void
    {
        Route::middleware(['web', 'tenant.app'])->group(function (): void {
            Route::get('/__test/reports', fn (Request $request) => $request->attributes->get('subscription_read_only')
                ? 'read-only'
                : 'writable')->name('app.test.reports');

            Route::post('/__test/products', fn () => 'written')->name('app.test.products');

            Route::get('/__test/pos', fn () => 'selling')->name('app.pos.terminal');
        });

        // The framework builds its name → route lookup in a `booted` callback,
        // which has already fired by the time a test registers anything. Without
        // this refresh route('app.test.reports') would not resolve.
        Route::getRoutes()->refreshNameLookups();
    }

    /** @param array<string, mixed> $attributes */
    protected function subscribe(array $attributes = [], ?string $state = null): Subscription
    {
        $factory = Subscription::factory()->forBusiness($this->business)->forPlan($this->plan);

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        return $factory->create($attributes);
    }

    protected function expireTheSubscription(): Subscription
    {
        return $this->subscribe(['ends_at' => now()->subDays(10), 'grace_days' => 0]);
    }

    // ------------------------------------------------------- no subscription

    public function test_a_tenant_with_no_subscription_is_sent_to_billing(): void
    {
        $this->actingAs($this->user, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('error');
    }

    public function test_a_tenant_with_no_subscription_can_still_reach_billing(): void
    {
        // Otherwise: redirect loop, and no way to start paying. This is why
        // CheckSubscription::$alwaysAllowed exists.
        $this->actingAs($this->user, 'web')->get(route('app.billing.index'))->assertOk();
        $this->actingAs($this->user, 'web')->get(route('app.billing.plans'))->assertOk();
    }

    public function test_logout_is_never_blocked_by_the_paywall(): void
    {
        $this->actingAs($this->user, 'web')
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_an_api_caller_with_no_subscription_gets_403_json(): void
    {
        $this->actingAs($this->user, 'web')
            ->getJson(route('app.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('error', 'no_subscription');
    }

    public function test_a_superseded_row_is_not_a_subscription(): void
    {
        // History (#176) must never be mistaken for entitlement.
        $this->subscribe(state: 'superseded');

        $this->actingAs($this->user, 'web')
            ->getJson(route('app.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('error', 'no_subscription');
    }

    public function test_a_guest_is_sent_to_login_not_to_billing(): void
    {
        // Proves the ORDER of the group: auth → tenant → subscription. A guest
        // has no tenant to bill, so asking them to pay would be nonsense.
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------- live and paid

    public function test_an_active_subscription_passes_the_gate(): void
    {
        $this->subscribe();

        $this->actingAs($this->user, 'web')->get(route('app.dashboard'))->assertOk();

        $this->actingAs($this->user, 'web')
            ->get(route('app.test.reports'))
            ->assertOk()
            ->assertSee('writable');
    }

    public function test_a_running_trial_passes_the_gate(): void
    {
        // #81: a trial is full access, not a crippled preview.
        $this->subscribe(state: 'trial');

        $this->actingAs($this->user, 'web')->get(route('app.dashboard'))->assertOk();
        $this->actingAs($this->user, 'web')->post(route('app.test.products'))->assertSee('written');
    }

    public function test_a_subscription_inside_its_grace_period_passes_the_gate(): void
    {
        // #127: grace is a warning, not a lockout — the tenant keeps working.
        $this->subscribe(state: 'inGrace');

        $this->actingAs($this->user, 'web')->get(route('app.dashboard'))->assertOk();
        $this->actingAs($this->user, 'web')->post(route('app.test.products'))->assertSee('written');
    }

    public function test_a_stale_active_column_does_not_get_a_lapsed_tenant_through(): void
    {
        // The cron never ran, so the column still reads Active. The gate must
        // still refuse, because access is derived from the DATES (#79).
        config()->set('subscription.expiry_behavior', ExpiryBehavior::Lock->value);

        $subscription = $this->subscribe([
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subDays(30),
            'grace_days' => 0,
        ]);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status, 'precondition: the column is stale');

        $this->actingAs($this->user, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('app.billing.index'));
    }

    public function test_a_stale_expired_column_does_not_lock_out_a_paid_tenant(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::Lock->value);

        $this->subscribe([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->addDays(20),
        ]);

        $this->actingAs($this->user, 'web')->get(route('app.dashboard'))->assertOk();
    }

    // ------------------------------------------------------ behaviour: lock

    public function test_lock_closes_everything_except_billing(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::Lock->value);

        $this->expireTheSubscription();

        $this->actingAs($this->user, 'web')
            ->get(route('app.test.reports'))
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('error');

        $this->actingAs($this->user, 'web')->get(route('app.billing.index'))->assertOk();
    }

    public function test_lock_answers_an_api_caller_with_403(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::Lock->value);

        $this->expireTheSubscription();

        $this->actingAs($this->user, 'web')
            ->getJson(route('app.test.reports'))
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_expired');
    }

    // ------------------------------------------------- behaviour: read_only

    public function test_read_only_lets_reads_through_and_flags_them(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::ReadOnly->value);

        $this->expireTheSubscription();

        // The tenant can still SEE their data — the whole reason this is the
        // default. The request is flagged so the UI can disable its buttons.
        $this->actingAs($this->user, 'web')
            ->get(route('app.test.reports'))
            ->assertOk()
            ->assertSee('read-only');
    }

    public function test_read_only_refuses_every_write(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::ReadOnly->value);

        $this->expireTheSubscription();

        // Checked on the HTTP METHOD, not on a route allow-list, so a write route
        // added in a later phase is covered without anyone remembering to list it.
        $response = $this->actingAs($this->user, 'web')->post(route('app.test.products'));

        $response->assertRedirect()->assertSessionHas('error');
        $response->assertDontSee('written');
    }

    public function test_read_only_write_refusal_is_403_for_an_api_caller(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::ReadOnly->value);

        $this->expireTheSubscription();

        $this->actingAs($this->user, 'web')
            ->postJson(route('app.test.products'))
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_read_only');
    }

    // --------------------------------------------------- behaviour: pos_off

    public function test_pos_off_closes_only_the_selling_screen(): void
    {
        config()->set('subscription.expiry_behavior', ExpiryBehavior::PosOff->value);

        $this->expireTheSubscription();

        $this->actingAs($this->user, 'web')
            ->get(route('app.pos.terminal'))
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('error');

        // Back office keeps working — including its writes. That is the entire
        // difference between pos_off and read_only.
        $this->actingAs($this->user, 'web')->get(route('app.test.reports'))->assertOk();
        $this->actingAs($this->user, 'web')->post(route('app.test.products'))->assertSee('written');
    }

    public function test_an_unrecognised_expiry_behaviour_falls_back_to_read_only(): void
    {
        // A typo in the operator's settings must not accidentally mean
        // "everything is free".
        config()->set('subscription.expiry_behavior', 'nonsense');

        $this->expireTheSubscription();

        $this->actingAs($this->user, 'web')->get(route('app.test.reports'))->assertOk()->assertSee('read-only');
        $this->actingAs($this->user, 'web')->post(route('app.test.products'))->assertSessionHas('error');
    }

    // -------------------------------------------------------- cancellation

    public function test_a_cancelled_subscription_stops_access_immediately(): void
    {
        // Cancelled mid-period: the dates still look live, but the tenant asked
        // to stop. Cancellation beats every other state.
        config()->set('subscription.expiry_behavior', ExpiryBehavior::Lock->value);

        $this->subscribe(['ends_at' => now()->addDays(20), 'cancelled_at' => now()]);

        $this->actingAs($this->user, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('app.billing.index'));
    }

    public function test_a_resumed_subscription_regains_access(): void
    {
        $this->subscribe(['ends_at' => now()->addDays(20), 'cancelled_at' => now()]);

        app(SubscriptionService::class)->resume($this->business);

        $this->actingAs($this->user, 'web')->get(route('app.dashboard'))->assertOk();
    }

    // ------------------------------------------------------ the gate itself

    public function test_every_business_facing_route_runs_the_subscription_gate(): void
    {
        // #187: bundling the gate into `tenant.app` is only worth anything if no
        // route escapes it. This walks the real route table instead of trusting
        // that whoever adds the next route remembers.
        $router = app('router');
        $unguarded = [];

        foreach ($router->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'app.')) {
                continue;
            }

            if (! in_array(CheckSubscription::class, $router->gatherRouteMiddleware($route), true)) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These business-facing routes skip the subscription gate: '.implode(', ', $unguarded)
        );
    }

    public function test_the_gate_reads_the_tenant_from_the_session_not_the_request(): void
    {
        // A tenant with no plan cannot borrow a paid tenant's subscription by
        // naming it in the query string (#197).
        $paid = Business::factory()->create();
        Subscription::factory()->forBusiness($paid)->forPlan($this->plan)->create();

        $this->actingAs($this->user, 'web')
            ->get(route('app.dashboard', ['business_id' => $paid->id]))
            ->assertRedirect(route('app.billing.index'));
    }
}
