<?php

namespace Tests\Feature\Organization;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\PosCounter;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
use App\Services\EmployeeService;
use App\Services\OrganizationProvisioner;
use App\Services\PlanLimitService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Staff accounts and their assignment (#50, #138, #140, #141).
 *
 * The through-line: every column that decides what a person may do or see —
 * role, branch, till, discount cap, active flag — is guarded on the model, so a
 * crafted form post cannot set them. They can only be written by
 * {@see EmployeeService}, and only after each value has been checked against the
 * current tenant.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Staff Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<string, bool>  $features
     * @param  array<string, int|null>  $limits
     */
    protected function subscribe(array $features = [], array $limits = [], ?Business $business = null): Plan
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        $limits = $limits + [
            LimitRegistry::EMPLOYEES => 10,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
        ];

        foreach ($limits as $code => $value) {
            $limit = Limit::query()->where('code', $code)->firstOrFail();
            $plan->limits()->attach($limit->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($business ?? $this->business)->forPlan($plan)->create();

        return $plan;
    }

    /** Subscribe, provision the org, and enter the tenant context. */
    protected function setUpBusiness(array $features = [], array $limits = []): void
    {
        $this->subscribe($features, $limits);
        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
    }

    protected function employees(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    /**
     * Build a fixture that really belongs to ANOTHER business.
     *
     * ⚠️ Not optional plumbing. `BelongsToTenant` stamps `business_id` from the
     * active context on create, so `Model::factory()->for($other)->create()`
     * while this test's tenant is active silently produces a row in THIS
     * business — and the cross-tenant test then passes for the wrong reason, or
     * fails for a reason that has nothing to do with the code under test.
     * `runFor()` switches the context for the duration and restores it after.
     *
     * @template T
     *
     * @param  callable(Business):T  $callback
     * @return T
     */
    protected function inAnotherBusiness(callable $callback)
    {
        $other = Business::factory()->create();

        return app(TenantContext::class)->runFor($other, fn () => $callback($other));
    }

    /** @return array<string, mixed> */
    protected function validEmployee(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aisha Khan',
            'email' => 'aisha@shop.test',
            'phone' => '0300 1234567',
            'password' => 'Str0ng!Passw0rd',
            'role_id' => null,
            'branch_id' => $this->branch->id,
            'pos_counter_id' => null,
            'max_discount_percent' => null,
            'is_active' => true,
        ], $overrides);
    }

    // ------------------------------------------------------------- creation

    public function test_an_employee_is_created_with_their_assignment(): void
    {
        $this->setUpBusiness();

        $role = Role::factory()->for($this->business)->withPermissions([PermissionRegistry::SALES_CREATE])->create();
        $counter = PosCounter::query()->firstOrFail();

        $employee = $this->employees()->create($this->validEmployee([
            'role_id' => $role->id,
            'pos_counter_id' => $counter->id,
            'max_discount_percent' => 15,
        ]));

        $this->assertSame($this->business->id, $employee->business_id);
        $this->assertSame($role->id, $employee->role_id);
        $this->assertSame($this->branch->id, $employee->branch_id);
        $this->assertSame($counter->id, $employee->pos_counter_id);
        $this->assertSame(15.0, (float) $employee->max_discount_percent);
        $this->assertFalse($employee->isOwner(), 'Ownership must never be granted through this form.');
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $employee->password));
    }

    public function test_adding_a_second_user_needs_the_multi_user_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::TEAM_MULTI_USER => false]);

        $this->expectException(FeatureUnavailableException::class);

        $this->employees()->create($this->validEmployee());
    }

    public function test_the_employee_quota_is_enforced(): void
    {
        // Two seats, and the owner already occupies one.
        $this->setUpBusiness([], [LimitRegistry::EMPLOYEES => 2]);

        $this->employees()->create($this->validEmployee());

        $this->expectException(LimitExceededException::class);

        $this->employees()->create($this->validEmployee(['email' => 'second@shop.test']));
    }

    public function test_removing_an_employee_frees_their_seat(): void
    {
        $this->setUpBusiness([], [LimitRegistry::EMPLOYEES => 2]);

        $employee = $this->employees()->create($this->validEmployee());

        $this->employees()->delete($employee, $this->owner);

        app(PlanLimitService::class)->flush();
        $this->assertSame(1, app(PlanLimitService::class)->usage(LimitRegistry::EMPLOYEES));

        // The row itself survives, so past work still resolves to a person (#104).
        $this->assertSoftDeleted('users', ['id' => $employee->id]);
    }

    // ------------------------------------------------- cross-tenant defences

    public function test_a_role_from_another_business_cannot_be_assigned(): void
    {
        $this->setUpBusiness();

        $otherRole = $this->inAnotherBusiness(
            fn (Business $other) => Role::factory()->for($other)->create()
        );

        $this->expectException(HttpException::class);

        $this->employees()->create($this->validEmployee(['role_id' => $otherRole->id]));
    }

    public function test_a_branch_from_another_business_cannot_be_assigned(): void
    {
        $this->setUpBusiness();

        $otherBranch = $this->inAnotherBusiness(
            fn (Business $other) => Branch::factory()->for($other)->create()
        );

        $this->expectException(HttpException::class);

        $this->employees()->create($this->validEmployee(['branch_id' => $otherBranch->id]));
    }

    public function test_a_counter_must_belong_to_the_chosen_branch(): void
    {
        $this->setUpBusiness();

        $second = app(BranchService::class)->create(['name' => 'Second']);
        $otherCounter = PosCounter::factory()->inBranch($second)->create();

        $this->expectException(HttpException::class);

        // Person in branch A, till in branch B — that is how a cashier ends up
        // selling from the wrong stock.
        $this->employees()->create($this->validEmployee([
            'branch_id' => $this->branch->id,
            'pos_counter_id' => $otherCounter->id,
        ]));
    }

    public function test_guarded_columns_cannot_be_mass_assigned(): void
    {
        $this->setUpBusiness();

        $otherBusiness = Business::factory()->create();
        $role = Role::factory()->for($this->business)->create();

        $user = new User([
            'name' => 'Sneaky',
            'email' => 'sneaky@shop.test',
            'password' => 'Str0ng!Passw0rd',
            // All of these must be ignored by fill().
            'business_id' => $otherBusiness->id,
            'is_business_owner' => true,
            'is_active' => true,
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'max_discount_percent' => 100,
        ]);
        $user->save();

        $fresh = $user->fresh();

        $this->assertSame($this->business->id, $fresh->business_id, 'business_id was mass-assigned.');
        $this->assertNull($fresh->role_id, 'role_id was mass-assigned.');
        $this->assertNull($fresh->branch_id, 'branch_id was mass-assigned.');
        $this->assertNull($fresh->max_discount_percent, 'The discount cap was mass-assigned.');
        $this->assertFalse((bool) $fresh->is_business_owner, 'Ownership was mass-assigned.');
    }

    // ------------------------------------------------------ self-lockout

    public function test_nobody_can_deactivate_themselves(): void
    {
        $this->setUpBusiness();

        $manager = $this->employees()->create($this->validEmployee());

        $this->employees()->setActive($manager, false, $manager);

        $this->assertTrue($manager->fresh()->is_active, 'A user switched themselves off.');
    }

    public function test_the_owner_cannot_be_deactivated_or_removed(): void
    {
        $this->setUpBusiness();

        $manager = $this->employees()->create($this->validEmployee());

        $this->employees()->setActive($this->owner, false, $manager);
        $this->assertTrue($this->owner->fresh()->is_active);

        $this->assertFalse($this->employees()->delete($this->owner, $manager));
        $this->assertNotSoftDeleted('users', ['id' => $this->owner->id]);
    }

    public function test_the_owners_role_and_branch_are_not_editable_from_the_staff_form(): void
    {
        $this->setUpBusiness();

        $role = Role::factory()->for($this->business)->create();

        $this->employees()->update($this->owner, [
            'name' => 'Renamed Owner',
            'email' => $this->owner->email,
            'role_id' => $role->id,
            'branch_id' => null,
            'is_active' => false,
        ], $this->owner);

        $fresh = $this->owner->fresh();

        $this->assertSame('Renamed Owner', $fresh->name, 'Ordinary details should still be editable.');
        $this->assertNull($fresh->role_id, 'A role was written onto the owner.');
        $this->assertTrue($fresh->is_active);
    }

    // ------------------------------------------------------- discount cap

    public function test_the_discount_cap_distinguishes_blank_from_zero(): void
    {
        $this->setUpBusiness();

        $noCap = $this->employees()->create($this->validEmployee(['max_discount_percent' => null]));
        $noDiscounts = $this->employees()->create($this->validEmployee([
            'email' => 'zero@shop.test',
            'max_discount_percent' => 0,
        ]));
        $capped = $this->employees()->create($this->validEmployee([
            'email' => 'capped@shop.test',
            'max_discount_percent' => 10,
        ]));

        // Blank = no personal cap.
        $this->assertNull($noCap->discountCap());
        $this->assertTrue($noCap->mayDiscount(50));

        // Zero = no discounts at all. A real answer, not "unset".
        $this->assertSame(0.0, $noDiscounts->discountCap());
        $this->assertFalse($noDiscounts->mayDiscount(1));
        $this->assertTrue($noDiscounts->mayDiscount(0));

        // A cap allows up to and including itself.
        $this->assertTrue($capped->mayDiscount(10));
        $this->assertFalse($capped->mayDiscount(10.01));

        // The owner is never capped.
        $this->assertNull($this->owner->discountCap());
        $this->assertTrue($this->owner->mayDiscount(100));
    }

    public function test_an_out_of_range_cap_is_clamped(): void
    {
        $this->setUpBusiness();

        $employee = $this->employees()->create($this->validEmployee(['max_discount_percent' => 250]));

        $this->assertSame(100.0, (float) $employee->max_discount_percent);
    }

    // ------------------------------------------------------------ passwords

    public function test_a_password_reset_replaces_the_hash(): void
    {
        $this->setUpBusiness();

        $employee = $this->employees()->create($this->validEmployee());
        $before = $employee->password;

        $this->employees()->resetPassword($employee, 'An0ther!Passw0rd');

        $this->assertNotSame($before, $employee->fresh()->password);
        $this->assertTrue(Hash::check('An0ther!Passw0rd', $employee->fresh()->password));
    }

    public function test_an_empty_password_on_edit_leaves_it_alone(): void
    {
        $this->setUpBusiness();

        $employee = $this->employees()->create($this->validEmployee());
        $before = $employee->password;

        $this->employees()->update($employee, [
            'name' => 'Aisha K.',
            'email' => $employee->email,
        ], $this->owner);

        $this->assertSame($before, $employee->fresh()->password);
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_add_an_employee_over_http(): void
    {
        $this->setUpBusiness();

        $role = Role::factory()->for($this->business)->create();

        $this->actingAs($this->owner)
            ->post(route('app.employees.store'), [
                'name' => 'Bilal Ahmed',
                'email' => 'bilal@shop.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'role_id' => $role->id,
                'branch_id' => $this->branch->id,
                'max_discount_percent' => 5,
                'is_active' => true,
            ])
            ->assertRedirect(route('app.employees.index'));

        $employee = User::query()->forBusiness($this->business->id)->where('email', 'bilal@shop.test')->first();

        $this->assertNotNull($employee);
        $this->assertSame($role->id, $employee->role_id);
        $this->assertSame($this->branch->id, $employee->branch_id);
        $this->assertSame(5.0, (float) $employee->max_discount_percent);
    }

    public function test_viewing_and_managing_staff_are_separate_authorities(): void
    {
        $this->setUpBusiness();

        $viewerRole = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::EMPLOYEES_VIEW])
            ->create();

        $viewer = $this->employees()->create($this->validEmployee([
            'email' => 'viewer@shop.test',
            'role_id' => $viewerRole->id,
        ]));

        $this->actingAs($viewer)->get(route('app.employees.index'))->assertOk();
        $this->actingAs($viewer)->get(route('app.employees.create'))->assertRedirect();
        $this->actingAs($viewer)
            ->postJson(route('app.employees.store'), [
                'name' => 'Ghost',
                'email' => 'ghost@shop.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'ghost@shop.test']);
    }

    public function test_a_request_cannot_assign_a_role_from_another_tenant_over_http(): void
    {
        $this->setUpBusiness();

        $otherRole = $this->inAnotherBusiness(
            fn (Business $other) => Role::factory()->for($other)->create()
        );

        $this->actingAs($this->owner)
            ->post(route('app.employees.store'), [
                'name' => 'Cross Tenant',
                'email' => 'cross@shop.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'role_id' => $otherRole->id,
                'branch_id' => $this->branch->id,
            ])
            ->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'cross@shop.test']);
    }

    public function test_another_businesss_employee_is_not_reachable(): void
    {
        $this->setUpBusiness();

        $outsider = $this->inAnotherBusiness(
            fn (Business $other) => User::factory()->for($other)->create()
        );

        $this->assertNotSame($this->business->id, $outsider->business_id);

        $this->actingAs($this->owner)
            ->get(route('app.employees.edit', $outsider))
            ->assertNotFound();
    }

    public function test_a_deactivated_employee_is_bounced_on_their_next_request(): void
    {
        $this->setUpBusiness();

        $employee = $this->employees()->create($this->validEmployee());
        $this->employees()->setActive($employee, false, $this->owner);

        // The tenant middleware ends the session rather than waiting for the
        // next login attempt (#138).
        $this->actingAs($employee->fresh())
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }
}
