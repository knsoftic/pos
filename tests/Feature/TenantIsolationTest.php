<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE most important test suite in this codebase. #116 / #117
 *
 * Multi-tenancy here is row-level: every tenant's data lives in the same tables,
 * separated only by `business_id`. That means the isolation guarantee is a code
 * guarantee, and it must be proven — not assumed. If any test in this file ever
 * fails, one business can see (or write into) another business's data.
 *
 * What is asserted:
 *   1. Reads are automatically filtered to the current tenant.
 *   2. Writes are automatically stamped with the current tenant.
 *   3. A request CANNOT override business_id (mass assignment / explicit set).
 *   4. Cross-tenant lookups return nothing (null / 404), never another tenant's row.
 *   5. The escape hatches (forBusiness / allTenants) behave exactly as documented.
 *   6. HTTP requests are scoped by the authenticated user, not by request input.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;

    protected Business $businessB;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::factory()->create(['name' => 'Business A']);
        $this->businessB = Business::factory()->create(['name' => 'Business B']);

        $this->userA = User::factory()->for($this->businessA)->create(['name' => 'Alice A']);
        $this->userB = User::factory()->for($this->businessB)->create(['name' => 'Bob B']);

        // Both tenants are paid up. Isolation is what this file tests — without a
        // live subscription the CheckSubscription gate would bounce every HTTP
        // test to billing and we would be asserting the paywall instead. The
        // paywall has its own suite: SubscriptionGateTest.
        Subscription::factory()->forBusiness($this->businessA)->create();
        Subscription::factory()->forBusiness($this->businessB)->create();
    }

    /** Enter a tenant context the same way SetBusinessTenant would. */
    protected function actAsTenant(Business $business): void
    {
        app(TenantContext::class)->setBusiness($business);
    }

    /** A paid invoice for one tenant, recorded inside that tenant's context. */
    protected function recordPayment(Business $business): SubscriptionPayment
    {
        return app(TenantContext::class)->runFor($business, function (): SubscriptionPayment {
            $payment = new SubscriptionPayment([
                'amount' => 29.00,
                'currency' => 'USD',
                'method' => 'bank_transfer',
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);

            $payment->subscription_id = Subscription::query()->firstOrFail()->id;
            $payment->save();

            return $payment;
        });
    }

    // ---------------------------------------------------------------- reads

    public function test_queries_are_automatically_scoped_to_the_current_business(): void
    {
        $this->actAsTenant($this->businessA);

        $names = User::query()->pluck('name');

        $this->assertCount(1, $names);
        $this->assertContains('Alice A', $names);
        $this->assertNotContains('Bob B', $names, 'Business A can see Business B users — ISOLATION BROKEN.');
    }

    public function test_switching_context_switches_the_visible_dataset(): void
    {
        $this->actAsTenant($this->businessA);
        $this->assertSame(1, User::count());
        $this->assertSame('Alice A', User::first()->name);

        $this->actAsTenant($this->businessB);
        $this->assertSame(1, User::count());
        $this->assertSame('Bob B', User::first()->name);
    }

    public function test_find_on_another_businesss_primary_key_returns_null(): void
    {
        $this->actAsTenant($this->businessA);

        $this->assertNull(
            User::find($this->userB->id),
            'A known-good ID from another tenant resolved — ISOLATION BROKEN.'
        );

        $this->assertNotNull(User::find($this->userA->id));
    }

    public function test_aggregates_and_relations_are_also_scoped(): void
    {
        User::factory()->for($this->businessB)->count(3)->create();

        $this->actAsTenant($this->businessA);

        // Aggregates go through the same builder, so they must be scoped too.
        $this->assertSame(1, User::count());
        $this->assertSame(0, User::where('name', 'Bob B')->count());
        $this->assertFalse(User::whereKey($this->userB->id)->exists());
    }

    public function test_no_tenant_context_means_no_auto_filter(): void
    {
        // Console commands / queued jobs / the super-admin panel run without a
        // tenant. In that state the scope must be a no-op, not a silent
        // "return nothing" (which would make admin screens look empty).
        app(TenantContext::class)->forget();

        $this->assertSame(2, User::count());
    }

    // --------------------------------------------------------------- writes

    public function test_creating_a_record_stamps_the_current_business(): void
    {
        $this->actAsTenant($this->businessA);

        $user = User::create([
            'name' => 'Stamped User',
            'email' => 'stamped@example.test',
            'password' => 'Password123',
        ]);

        $this->assertSame($this->businessA->id, $user->business_id);
    }

    public function test_business_id_cannot_be_mass_assigned(): void
    {
        $this->actAsTenant($this->businessA);

        // Simulates a tampered form post containing business_id.
        $user = User::create([
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'password' => 'Password123',
            'business_id' => $this->businessB->id,
            'is_business_owner' => true,
            'is_active' => true,
        ]);

        $this->assertSame(
            $this->businessA->id,
            $user->business_id,
            'business_id was mass-assignable — a user could plant rows in another tenant.'
        );

        // Privilege fields are guarded as well (#132) — re-read from the DB so we
        // assert the persisted value, not the unsaved in-memory attribute.
        $this->assertFalse(
            $user->fresh()->is_business_owner,
            'is_business_owner was mass-assignable — privilege escalation.'
        );
    }

    public function test_explicitly_setting_business_id_is_overridden_by_the_context(): void
    {
        $this->actAsTenant($this->businessA);

        $user = new User([
            'name' => 'Forced',
            'email' => 'forced@example.test',
            'password' => 'Password123',
        ]);
        $user->business_id = $this->businessB->id; // deliberate, in-code override attempt
        $user->save();

        $this->assertSame(
            $this->businessA->id,
            $user->fresh()->business_id,
            'The active tenant context must win over any business_id set on the model.'
        );
    }

    // -------------------------------------------------------- escape hatches

    public function test_for_business_scope_targets_exactly_one_other_tenant(): void
    {
        $this->actAsTenant($this->businessA);

        $users = User::forBusiness($this->businessB->id)->get();

        $this->assertCount(1, $users);
        $this->assertSame('Bob B', $users->first()->name);
    }

    public function test_all_tenants_scope_spans_every_business(): void
    {
        $this->actAsTenant($this->businessA);

        $this->assertSame(2, User::allTenants()->count());
    }

    public function test_run_for_restores_the_previous_context(): void
    {
        $context = app(TenantContext::class);
        $context->setBusiness($this->businessA);

        $seen = $context->runFor($this->businessB, fn () => User::pluck('name')->all());

        $this->assertSame(['Bob B'], $seen);
        $this->assertSame(
            $this->businessA->id,
            $context->businessId(),
            'runFor() must restore the outer tenant context — leaking it would scope later queries to the wrong business.'
        );
    }

    // ------------------------------------------------ billing rows (#82, #132)

    public function test_subscriptions_are_scoped_to_the_current_business(): void
    {
        // A subscription is the most sensitive row a tenant owns after its money:
        // seeing someone else's would leak what they pay and when they lapse.
        $this->actAsTenant($this->businessA);

        $visible = Subscription::query()->get();

        $this->assertCount(1, $visible);
        $this->assertSame($this->businessA->id, $visible->first()->business_id);

        $othersId = Subscription::allTenants()
            ->where('business_id', $this->businessB->id)
            ->value('id');

        $this->assertNull(
            Subscription::find($othersId),
            'Another tenant\'s subscription resolved by ID — ISOLATION BROKEN.'
        );
    }

    public function test_a_subscription_cannot_be_created_into_another_tenant(): void
    {
        $plan = Plan::factory()->monthly()->create();

        $this->actAsTenant($this->businessA);

        $subscription = new Subscription([
            'plan_id' => $plan->id,
            'billing_cycle' => BillingCycle::Monthly,
            'price' => 29.00,
            'currency' => 'USD',
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'business_id' => $this->businessB->id, // tampered payload
        ]);
        $subscription->business_id = $this->businessB->id; // and an in-code override
        $subscription->save();

        $this->assertSame(
            $this->businessA->id,
            $subscription->fresh()->business_id,
            'A subscription was written into another tenant — one business could buy a plan for someone else.'
        );
    }

    public function test_payments_are_scoped_to_the_current_business(): void
    {
        $this->recordPayment($this->businessA);
        $this->recordPayment($this->businessB);

        $this->actAsTenant($this->businessA);

        $visible = SubscriptionPayment::query()->get();

        $this->assertCount(1, $visible, 'A tenant can see another tenant\'s payments — ISOLATION BROKEN.');
        $this->assertSame($this->businessA->id, $visible->first()->business_id);
        $this->assertSame(2, SubscriptionPayment::allTenants()->count(), 'The operator must still see both.');
    }

    public function test_a_payment_cannot_be_planted_on_another_tenants_subscription(): void
    {
        $othersSubscriptionId = Subscription::allTenants()
            ->where('business_id', $this->businessB->id)
            ->value('id');

        $this->actAsTenant($this->businessA);

        // ⚠️ FINANCIAL RECORD. If business_id could be steered from the payload,
        // one tenant's payment would land on another's ledger (#132 / #198).
        $payment = new SubscriptionPayment([
            'amount' => 29.00,
            'currency' => 'USD',
            'method' => 'bank_transfer',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'business_id' => $this->businessB->id, // tampered payload
        ]);
        $payment->subscription_id = $othersSubscriptionId; // as a service layer would
        $payment->business_id = $this->businessB->id;      // deliberate override attempt
        $payment->save();

        $this->assertSame(
            $this->businessA->id,
            $payment->fresh()->business_id,
            'A payment was booked against another tenant — the ledger is not isolated.'
        );
    }

    // ------------------------------------------------------------ over HTTP

    public function test_dashboard_only_ever_shows_the_authenticated_users_business(): void
    {
        User::factory()->for($this->businessB)->create(['name' => 'Bee Two']);

        $this->actingAs($this->userA, 'web')
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Business A')
            ->assertSee('Alice A')
            ->assertDontSee('Business B')
            ->assertDontSee('Bob B')
            ->assertDontSee('Bee Two');
    }

    public function test_request_input_cannot_change_the_resolved_tenant(): void
    {
        // The classic attack: pass someone else's business_id along with the
        // request. The tenant is resolved from the session user only (#197).
        $this->actingAs($this->userA, 'web')
            ->get(route('app.dashboard', ['business_id' => $this->businessB->id, 'business' => 'business-b']))
            ->assertOk()
            ->assertSee('Business A')
            ->assertDontSee('Bob B');
    }

    public function test_suspended_business_cannot_reach_the_app(): void
    {
        $this->businessA->update(['status' => Business::STATUS_SUSPENDED]);

        $this->actingAs($this->userA, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_deactivated_user_is_logged_out_by_the_tenant_middleware(): void
    {
        $this->userA->forceFill(['is_active' => false])->save();

        $this->actingAs($this->userA, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_user_whose_business_was_soft_deleted_cannot_reach_the_app(): void
    {
        $this->businessA->delete();

        $this->actingAs($this->userA, 'web')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }
}
