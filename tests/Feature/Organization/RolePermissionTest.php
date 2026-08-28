<?php

namespace Tests\Feature\Organization;

use App\Exceptions\PermissionDeniedException;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Support\FeatureRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Roles, permissions, and the three-layer check (#51, #52, #187, #188).
 *
 * The layers, and what each test here is really asking:
 *   1. SUBSCRIPTION FEATURE — the plan must include the capability. A role that
 *      grants "process returns" on a plan without returns grants nothing.
 *   2. USER PERMISSION — the role must list the code. The owner is above roles.
 *   3. TENANT — the user must belong to the business the request runs for.
 *
 * The order matters as much as the layers: feature is checked first so a tenant
 * on the wrong plan is told to upgrade, not told they lack a permission their
 * owner could not grant them anyway.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Permission Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $this->subscribeToAllFeatures();
        $this->registerTestRoutes();
    }

    // ------------------------------------------------------------- fixtures

    /** Everything in the plan, so a denial can only come from the role. */
    protected function subscribeToAllFeatures(?Business $business = null): Plan
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        Subscription::factory()
            ->forBusiness($business ?? $this->business)
            ->forPlan($plan)
            ->create();

        return $plan;
    }

    /** @param  list<string>  $codes */
    protected function staffWith(array $codes, ?Business $business = null): User
    {
        $business ??= $this->business;

        $role = Role::factory()
            ->for($business)
            ->withPermissions($codes)
            ->create();

        return User::factory()->for($business)->create(['role_id' => $role->id]);
    }

    protected function permissions(): PermissionService
    {
        // Fresh instance each time: the per-request role memo must never mask a
        // change the caller has just made.
        return app(PermissionService::class);
    }

    protected function registerTestRoutes(): void
    {
        Route::middleware(['web', 'tenant.app'])->group(function (): void {
            Route::get('/__test/staff', fn () => 'staff list')
                ->middleware('permission:'.PermissionRegistry::EMPLOYEES_VIEW)
                ->name('app.test.staff');

            Route::get('/__test/void', fn () => 'voided')
                ->middleware('permission:'.PermissionRegistry::SALES_VOID)
                ->name('app.test.void');

            Route::get('/__test/two', fn () => 'both')
                ->middleware('permission:'.PermissionRegistry::SALES_VIEW.','.PermissionRegistry::SALES_VOID)
                ->name('app.test.two');

            Route::get('/__test/unknown', fn () => 'never')
                ->middleware('permission:sales.teleport')
                ->name('app.test.unknown');
        });

        Route::getRoutes()->refreshNameLookups();
    }

    // ------------------------------------------------------- layer 2: roles

    public function test_the_owner_holds_every_permission_without_a_role(): void
    {
        $this->assertNull($this->owner->role_id, 'The owner is deliberately roleless.');

        foreach (PermissionRegistry::codes() as $code) {
            $this->assertTrue(
                $this->permissions()->allows($code, $this->owner),
                "The owner was refused [{$code}] in their own business."
            );
        }
    }

    public function test_a_user_with_no_role_can_do_nothing(): void
    {
        $stranger = User::factory()->for($this->business)->create(['role_id' => null]);

        foreach (PermissionRegistry::codes() as $code) {
            $this->assertFalse(
                $this->permissions()->allows($code, $stranger),
                "A user with no role was granted [{$code}]."
            );
        }
    }

    public function test_a_role_grants_exactly_what_it_lists(): void
    {
        $cashier = $this->staffWith([
            PermissionRegistry::SALES_CREATE,
            PermissionRegistry::SALES_VIEW,
        ]);

        $this->assertTrue($this->permissions()->allows(PermissionRegistry::SALES_CREATE, $cashier));
        $this->assertTrue($this->permissions()->allows(PermissionRegistry::SALES_VIEW, $cashier));

        // The neighbouring, more dangerous ones are NOT implied.
        $this->assertFalse($this->permissions()->allows(PermissionRegistry::SALES_VOID, $cashier));
        $this->assertFalse($this->permissions()->allows(PermissionRegistry::SALES_RETURN, $cashier));
    }

    public function test_a_deactivated_user_loses_every_permission(): void
    {
        $cashier = $this->staffWith([PermissionRegistry::SALES_CREATE]);

        // Set directly, not via update(): `is_active` is guarded on the model,
        // so mass assignment would silently do nothing and the test would pass
        // for the wrong reason.
        $cashier->is_active = false;
        $cashier->save();

        $this->assertFalse($this->permissions()->allows(PermissionRegistry::SALES_CREATE, $cashier->fresh()));
    }

    public function test_all_of_and_any_of_combine_codes(): void
    {
        $staff = $this->staffWith([PermissionRegistry::SALES_VIEW]);

        $codes = [PermissionRegistry::SALES_VIEW, PermissionRegistry::SALES_VOID];

        $this->assertFalse($this->permissions()->allOf($codes, $staff));
        $this->assertTrue($this->permissions()->anyOf($codes, $staff));
    }

    public function test_authorize_throws_for_a_permission_the_role_lacks(): void
    {
        $staff = $this->staffWith([PermissionRegistry::SALES_VIEW]);

        $this->expectException(PermissionDeniedException::class);

        $this->permissions()->authorize(PermissionRegistry::SALES_VOID, $staff);
    }

    public function test_an_unknown_permission_code_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->permissions()->allows('sales.teleport', $this->owner);
    }

    // ----------------------------------------------------- layer 1: features

    public function test_a_permission_is_refused_when_its_feature_is_not_in_the_plan(): void
    {
        // A plan with everything EXCEPT returns.
        $business = Business::factory()->create();
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $feature->code !== FeatureRegistry::SALES_RETURNS,
            ]);
        }

        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();

        $staff = $this->staffWith([
            PermissionRegistry::SALES_RETURN,
            PermissionRegistry::SALES_CREATE,
        ], $business);

        // The role says yes. The plan says no. The plan wins.
        $this->assertTrue($staff->role->grants(PermissionRegistry::SALES_RETURN));
        $this->assertFalse($this->permissions()->allows(PermissionRegistry::SALES_RETURN, $staff));

        // …and the rest of the role still works.
        $this->assertTrue($this->permissions()->allows(PermissionRegistry::SALES_CREATE, $staff));
    }

    public function test_the_owner_is_also_bound_by_the_plan(): void
    {
        $business = Business::factory()->create();
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $feature->code !== FeatureRegistry::TEAM_ROLES,
            ]);
        }

        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();
        $owner = User::factory()->for($business)->create(['is_business_owner' => true]);

        // Being the owner outranks ROLES, not the subscription — otherwise the
        // paywall would be optional for the one account that matters most.
        $this->assertFalse($this->permissions()->allows(PermissionRegistry::ROLES_MANAGE, $owner));
        $this->assertTrue($this->permissions()->allows(PermissionRegistry::SETTINGS_MANAGE, $owner));
    }

    public function test_a_business_with_no_subscription_grants_only_ungated_permissions(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->for($business)->create(['is_business_owner' => true]);

        // Feature-backed permissions are refused outright…
        $this->assertFalse($this->permissions()->allows(PermissionRegistry::SALES_CREATE, $owner));

        // …while the ones no feature gates stay available, so an unsubscribed
        // owner can still see their own settings and fix the situation.
        $this->assertTrue($this->permissions()->allows(PermissionRegistry::SETTINGS_MANAGE, $owner));
    }

    public function test_only_permissions_the_plan_supports_are_grantable(): void
    {
        $business = Business::factory()->create();
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $feature->code !== FeatureRegistry::SALES_RETURNS,
            ]);
        }

        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();

        app(TenantContext::class)->setBusiness($business);

        $grantable = $this->permissions()->grantableCodes();

        $this->assertNotContains(PermissionRegistry::SALES_RETURN, $grantable);
        $this->assertContains(PermissionRegistry::SALES_CREATE, $grantable);

        app(TenantContext::class)->forget();
    }

    // ------------------------------------------------------ layer 3: tenancy

    public function test_a_user_from_another_business_is_refused_even_with_the_permission(): void
    {
        $otherBusiness = Business::factory()->create();
        $this->subscribeToAllFeatures($otherBusiness);

        $outsider = $this->staffWith([PermissionRegistry::SALES_VOID], $otherBusiness);

        // With this business active, the outsider's own permission is irrelevant.
        app(TenantContext::class)->setBusiness($this->business);

        $this->assertFalse(
            $this->permissions()->allows(PermissionRegistry::SALES_VOID, $outsider),
            'A user from another tenant passed a permission check.'
        );

        app(TenantContext::class)->forget();
    }

    // --------------------------------------------------------- the middleware

    public function test_the_middleware_refuses_a_request_the_role_does_not_cover(): void
    {
        $cashier = $this->staffWith([PermissionRegistry::SALES_CREATE]);

        $this->actingAs($cashier)
            ->get('/__test/staff')
            ->assertRedirect();

        $this->assertNotNull(session('permission_denied'));
    }

    public function test_the_middleware_lets_a_permitted_request_through(): void
    {
        $supervisor = $this->staffWith([PermissionRegistry::EMPLOYEES_VIEW]);

        $this->actingAs($supervisor)
            ->get('/__test/staff')
            ->assertOk()
            ->assertSee('staff list');
    }

    public function test_the_middleware_answers_an_api_caller_with_403(): void
    {
        $cashier = $this->staffWith([PermissionRegistry::SALES_CREATE]);

        $this->actingAs($cashier)
            ->getJson('/__test/void')
            ->assertStatus(403)
            ->assertJsonPath('error', 'permission_denied');
    }

    public function test_the_middleware_ands_multiple_codes(): void
    {
        $partial = $this->staffWith([PermissionRegistry::SALES_VIEW]);
        $full = $this->staffWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::SALES_VOID]);

        $this->actingAs($partial)->getJson('/__test/two')->assertStatus(403);
        $this->actingAs($full)->getJson('/__test/two')->assertOk();
    }

    public function test_an_unknown_code_in_a_route_gate_fails_loudly(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);

        $this->actingAs($this->owner)->get('/__test/unknown');
    }

    public function test_the_gate_facade_agrees_with_the_service(): void
    {
        $cashier = $this->staffWith([PermissionRegistry::SALES_CREATE]);

        // Blade's @can and the service must never disagree — that is the whole
        // reason there is no separate Gate::before owner bypass.
        $this->assertTrue($cashier->can(PermissionRegistry::SALES_CREATE));
        $this->assertFalse($cashier->can(PermissionRegistry::SALES_VOID));
        $this->assertTrue($this->owner->can(PermissionRegistry::SALES_VOID));
    }

    // ------------------------------------------------------- the registry

    public function test_permission_codes_never_collide_with_feature_codes(): void
    {
        // The two vocabularies live side by side in gates and views; one string
        // meaning both things would be a very quiet disaster.
        $collisions = array_intersect(PermissionRegistry::codes(), FeatureRegistry::codes());

        $this->assertSame([], array_values($collisions));
    }

    public function test_every_permission_declares_a_known_feature_or_none(): void
    {
        foreach (PermissionRegistry::all() as $code => $meta) {
            if ($meta['feature'] !== null) {
                $this->assertTrue(
                    FeatureRegistry::exists($meta['feature']),
                    "Permission [{$code}] points at a feature that does not exist."
                );
            }
        }
    }

    public function test_the_sensitive_list_covers_what_spec_52_calls_out(): void
    {
        $sensitive = PermissionRegistry::sensitiveCodes();

        foreach ([
            PermissionRegistry::PRODUCTS_VIEW_COST,
            PermissionRegistry::REPORTS_VIEW_PROFIT,
            PermissionRegistry::REPORTS_EXPORT,
            PermissionRegistry::SALES_VOID,
            PermissionRegistry::SALES_RETURN,
            PermissionRegistry::ROLES_MANAGE,
        ] as $code) {
            $this->assertContains($code, $sensitive, "[{$code}] should be flagged sensitive (#52).");
        }
    }

    // --------------------------------------------------------- role service

    public function test_a_new_business_gets_its_starter_roles(): void
    {
        $business = Business::factory()->create();

        app(RoleService::class)->seedSystemRoles($business);

        $roles = Role::query()->forBusiness($business->id)->get();

        $this->assertCount(3, $roles);
        $this->assertEqualsCanonicalizing(
            ['manager', 'cashier', 'stock-keeper'],
            $roles->pluck('slug')->all()
        );
        $this->assertTrue($roles->every(fn (Role $role) => $role->is_system));
    }

    public function test_seeding_starter_roles_twice_does_not_duplicate_them(): void
    {
        $business = Business::factory()->create();
        $service = app(RoleService::class);

        $service->seedSystemRoles($business);
        $service->seedSystemRoles($business);

        $this->assertSame(3, Role::query()->forBusiness($business->id)->count());
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $staff = $this->staffWith([PermissionRegistry::SALES_VIEW]);
        $role = $staff->role;

        app(TenantContext::class)->setBusiness($this->business);

        $this->assertFalse(app(RoleService::class)->delete($role));
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);

        app(TenantContext::class)->forget();
    }

    public function test_a_starter_role_cannot_be_deleted_even_when_unused(): void
    {
        $role = Role::factory()->for($this->business)->system()->create();

        app(TenantContext::class)->setBusiness($this->business);

        $this->assertFalse(app(RoleService::class)->delete($role));

        app(TenantContext::class)->forget();
    }

    public function test_an_unused_custom_role_is_deleted(): void
    {
        $role = Role::factory()->for($this->business)->create();

        app(TenantContext::class)->setBusiness($this->business);

        $this->assertTrue(app(RoleService::class)->delete($role));
        $this->assertSoftDeleted('roles', ['id' => $role->id]);

        app(TenantContext::class)->forget();
    }

    public function test_saving_a_role_keeps_permissions_the_plan_no_longer_covers(): void
    {
        /*
         | A tenant downgrades, losing returns. The owner then edits an unrelated
         | part of the role. The returns permission must survive: the editor never
         | showed it, so its absence from the submission is not a decision to
         | remove it — and an upgrade must not come back with the box unticked.
         */
        $business = Business::factory()->create();
        $role = Role::factory()->for($business)->withPermissions([
            PermissionRegistry::SALES_RETURN,
            PermissionRegistry::SALES_CREATE,
        ])->create();

        $plan = Plan::factory()->monthly()->create();
        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $feature->code !== FeatureRegistry::SALES_RETURNS,
            ]);
        }
        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();

        app(TenantContext::class)->setBusiness($business);

        app(RoleService::class)->update(
            $role,
            ['name' => 'Renamed', 'description' => null],
            [PermissionRegistry::SALES_CREATE], // what the editor could show
        );

        $codes = $role->fresh()->permissionCodes();

        $this->assertContains(PermissionRegistry::SALES_RETURN, $codes, 'A dormant permission was silently dropped.');
        $this->assertContains(PermissionRegistry::SALES_CREATE, $codes);

        app(TenantContext::class)->forget();
    }

    public function test_a_role_cannot_be_given_a_permission_outside_the_plan(): void
    {
        $business = Business::factory()->create();

        $plan = Plan::factory()->monthly()->create();
        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $feature->code !== FeatureRegistry::SALES_RETURNS,
            ]);
        }
        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();

        app(TenantContext::class)->setBusiness($business);

        $role = app(RoleService::class)->create(
            ['name' => 'Optimist', 'description' => null],
            [PermissionRegistry::SALES_RETURN, PermissionRegistry::SALES_CREATE],
        );

        $this->assertNotContains(PermissionRegistry::SALES_RETURN, $role->fresh()->permissionCodes());
        $this->assertContains(PermissionRegistry::SALES_CREATE, $role->fresh()->permissionCodes());

        app(TenantContext::class)->forget();
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_open_and_submit_the_role_editor(): void
    {
        $this->actingAs($this->owner)->get(route('app.roles.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.roles.store'), [
                'name' => 'Shift Supervisor',
                'description' => 'Runs the evening shift',
                'permissions' => [PermissionRegistry::SALES_VIEW, PermissionRegistry::SALES_VOID],
            ])
            ->assertRedirect(route('app.roles.index'));

        $role = Role::query()->forBusiness($this->business->id)->where('name', 'Shift Supervisor')->first();

        $this->assertNotNull($role);
        $this->assertEqualsCanonicalizing(
            [PermissionRegistry::SALES_VIEW, PermissionRegistry::SALES_VOID],
            $role->permissionCodes()
        );
    }

    public function test_someone_without_roles_manage_cannot_reach_the_role_screens(): void
    {
        $cashier = $this->staffWith([PermissionRegistry::SALES_CREATE]);

        $this->actingAs($cashier)->get(route('app.roles.index'))->assertRedirect();
        $this->actingAs($cashier)->get(route('app.roles.create'))->assertRedirect();
        $this->actingAs($cashier)->postJson(route('app.roles.store'), ['name' => 'Sneaky'])->assertStatus(403);

        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky']);
    }

    public function test_a_role_from_another_business_is_not_reachable(): void
    {
        $otherBusiness = Business::factory()->create();
        $otherRole = Role::factory()->for($otherBusiness)->create();

        $this->actingAs($this->owner)
            ->get(route('app.roles.edit', $otherRole))
            ->assertNotFound();
    }
}
