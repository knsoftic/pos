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
use App\Services\OrganizationProvisioner;
use App\Services\PlanLimitService;
use App\Services\PosCounterService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Branches, tills, and branch-level data control (#47, #48, #49, #138).
 *
 * The rule being pinned down, in one sentence: the tenant scope decides WHICH
 * BUSINESS, and the branch scope decides WHICH SHOPS INSIDE IT — the second can
 * only ever narrow the first, never widen it.
 *
 * Owner  → every branch.
 * Staff  → the branch they are assigned to, and nothing else.
 * Staff with no branch → nothing at all. Fail closed, like everywhere else.
 */
class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Branch Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<string, bool>  $features  overrides on top of "everything on"
     * @param  array<string, int|null>  $limits  code => ceiling (null = unlimited)
     */
    protected function subscribe(array $features = [], array $limits = [], ?Business $business = null): Plan
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, [
                'is_enabled' => $features[$feature->code] ?? true,
            ]);
        }

        /*
         | Generous quotas by default. The registry's own fallback is ONE branch
         | and ONE counter (see LimitRegistry), which is the right default for a
         | real unconfigured plan but would make every test in this file fail on
         | the quota before it reached the thing it is actually testing. The
         | quota itself gets its own test, which overrides these.
         */
        $limits = $limits + [
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ];

        foreach ($limits as $code => $value) {
            $limit = Limit::query()->where('code', $code)->firstOrFail();
            $plan->limits()->attach($limit->id, ['value' => $value]);
        }

        Subscription::factory()
            ->forBusiness($business ?? $this->business)
            ->forPlan($plan)
            ->create();

        return $plan;
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function branchContext(): BranchContext
    {
        return app(BranchContext::class);
    }

    // ------------------------------------------------------- provisioning

    public function test_a_new_business_gets_a_main_branch_and_a_first_counter(): void
    {
        $this->subscribe();

        app(OrganizationProvisioner::class)->provision($this->business);

        $branches = Branch::query()->forBusiness($this->business->id)->get();
        $counters = PosCounter::query()->allBranches()->forBusiness($this->business->id)->get();

        $this->assertCount(1, $branches);
        $this->assertTrue($branches->first()->is_main);
        $this->assertCount(1, $counters);
        $this->assertSame($branches->first()->id, $counters->first()->branch_id);

        // The owner is parked in it, so their work has a home from day one.
        $this->assertSame($branches->first()->id, $this->owner->fresh()->branch_id);
    }

    public function test_provisioning_twice_changes_nothing(): void
    {
        $this->subscribe();
        $provisioner = app(OrganizationProvisioner::class);

        $provisioner->provision($this->business);
        $provisioner->provision($this->business);

        $this->assertSame(1, Branch::query()->forBusiness($this->business->id)->count());
        $this->assertSame(1, PosCounter::query()->allBranches()->forBusiness($this->business->id)->count());
    }

    // ------------------------------------------------------- creation gates

    public function test_a_second_branch_needs_the_multi_branch_feature(): void
    {
        $this->subscribe([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);
        $this->tenant()->setBusiness($this->business);

        $service = app(BranchService::class);

        // The FIRST branch is always allowed — every business has one shop.
        $service->ensureMainBranch($this->business);

        $this->expectException(FeatureUnavailableException::class);
        $service->create(['name' => 'Second Shop']);
    }

    public function test_a_single_branch_tenant_can_still_edit_its_own_branch(): void
    {
        // The permission is deliberately not gated on multi-branch: a one-shop
        // business must be able to rename its shop.
        $this->subscribe([FeatureRegistry::BRANCHES_MULTI_BRANCH => false]);
        $this->tenant()->setBusiness($this->business);

        $branch = app(BranchService::class)->ensureMainBranch($this->business);

        app(BranchService::class)->update($branch, ['name' => 'Renamed Shop', 'code' => 'MAIN']);

        $this->assertSame('Renamed Shop', $branch->fresh()->name);
        $this->assertTrue($this->owner->can(PermissionRegistry::BRANCHES_MANAGE));
    }

    public function test_the_branch_quota_is_enforced(): void
    {
        $this->subscribe([], [LimitRegistry::BRANCHES => 2]);
        $this->tenant()->setBusiness($this->business);

        $service = app(BranchService::class);
        $service->ensureMainBranch($this->business);
        $service->create(['name' => 'Second']);

        $this->expectException(LimitExceededException::class);
        $service->create(['name' => 'Third']);
    }

    public function test_branch_and_counter_usage_is_counted_for_the_whole_business(): void
    {
        $this->subscribe([], [LimitRegistry::BRANCHES => 5, LimitRegistry::POS_COUNTERS => 5]);
        $this->tenant()->setBusiness($this->business);

        app(OrganizationProvisioner::class)->provision($this->business);
        app(BranchService::class)->create(['name' => 'Second']);

        $limits = app(PlanLimitService::class);
        $limits->flush();

        // Counted across every branch, not just the ones the viewer can see: a
        // quota is a fact about the business.
        $this->assertSame(2, $limits->usage(LimitRegistry::BRANCHES));
        $this->assertSame(1, $limits->usage(LimitRegistry::POS_COUNTERS));
    }

    public function test_a_second_counter_needs_the_multi_counter_feature(): void
    {
        $this->subscribe([FeatureRegistry::POS_MULTI_COUNTER => false]);
        $this->tenant()->setBusiness($this->business);

        $branch = app(BranchService::class)->ensureMainBranch($this->business);
        app(PosCounterService::class)->ensureDefaultCounter($this->business, $branch);

        $this->expectException(FeatureUnavailableException::class);
        app(PosCounterService::class)->create(['branch_id' => $branch->id, 'name' => 'Counter 2']);
    }

    // --------------------------------------------------- main branch rules

    public function test_the_main_branch_cannot_be_closed_or_deleted(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $service = app(BranchService::class);
        $main = $service->ensureMainBranch($this->business);

        $service->setActive($main, false);
        $this->assertTrue($main->fresh()->is_active, 'The main branch was closed.');

        $this->assertFalse($service->delete($main));
        $this->assertDatabaseHas('branches', ['id' => $main->id, 'deleted_at' => null]);
    }

    public function test_promoting_another_branch_steps_the_old_main_down(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $service = app(BranchService::class);
        $main = $service->ensureMainBranch($this->business);
        $second = $service->create(['name' => 'Second']);

        $service->makeMain($second);

        $this->assertFalse($main->fresh()->is_main);
        $this->assertTrue($second->fresh()->is_main);
        $this->assertSame(
            1,
            Branch::query()->forBusiness($this->business->id)->where('is_main', true)->count(),
            'A business must have exactly one main branch.'
        );
    }

    public function test_a_branch_with_staff_or_tills_is_not_deleted(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $service = app(BranchService::class);
        $service->ensureMainBranch($this->business);
        $second = $service->create(['name' => 'Second']);

        PosCounter::factory()->inBranch($second)->create();

        $this->assertFalse($service->delete($second));
        $this->assertDatabaseHas('branches', ['id' => $second->id, 'deleted_at' => null]);
    }

    // ------------------------------------------------------- the branch gate

    public function test_the_owner_reaches_every_branch(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $main = app(BranchService::class)->ensureMainBranch($this->business);
        $second = app(BranchService::class)->create(['name' => 'Second']);

        $this->branchContext()->forUser($this->owner);

        $this->assertFalse($this->branchContext()->isRestricted());
        $this->assertTrue($this->branchContext()->allows($main->id));
        $this->assertTrue($this->branchContext()->allows($second->id));
    }

    public function test_staff_are_confined_to_their_own_branch(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $main = app(BranchService::class)->ensureMainBranch($this->business);
        $second = app(BranchService::class)->create(['name' => 'Second']);

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $main->id]);

        $this->branchContext()->forUser($cashier);

        $this->assertTrue($this->branchContext()->isRestricted());
        $this->assertTrue($this->branchContext()->allows($main->id));
        $this->assertFalse($this->branchContext()->allows($second->id));
    }

    public function test_staff_with_no_branch_reach_nothing(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $main = app(BranchService::class)->ensureMainBranch($this->business);
        $stranded = User::factory()->for($this->business)->create(['branch_id' => null]);

        $this->branchContext()->forUser($stranded);

        $this->assertTrue($this->branchContext()->isRestricted());
        $this->assertFalse($this->branchContext()->allows($main->id), 'No branch must mean no branch data.');
        $this->assertFalse($this->branchContext()->allows(null));
    }

    public function test_counters_are_filtered_to_the_branches_a_user_can_reach(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $main = app(BranchService::class)->ensureMainBranch($this->business);
        $second = app(BranchService::class)->create(['name' => 'Second']);

        $mainCounter = PosCounter::factory()->inBranch($main)->create();
        $secondCounter = PosCounter::factory()->inBranch($second)->create();

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $main->id]);
        $this->branchContext()->forUser($cashier);

        $visible = PosCounter::query()->pluck('id')->all();

        $this->assertContains($mainCounter->id, $visible);
        $this->assertNotContains($secondCounter->id, $visible, 'A cashier saw another branch\'s till.');

        // The other branch's counter is not reachable by id either.
        $this->assertNull(PosCounter::query()->find($secondCounter->id));

        // And the escape hatch still works for code that legitimately needs it.
        $this->assertNotNull(PosCounter::query()->allBranches()->find($secondCounter->id));
    }

    public function test_a_till_cannot_be_installed_in_an_unreachable_branch(): void
    {
        $this->subscribe();
        $this->tenant()->setBusiness($this->business);

        $main = app(BranchService::class)->ensureMainBranch($this->business);
        $second = app(BranchService::class)->create(['name' => 'Second']);

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $main->id]);
        $this->branchContext()->forUser($cashier);

        $this->expectException(HttpException::class);

        app(PosCounterService::class)->create(['branch_id' => $second->id, 'name' => 'Sneaky till']);
    }

    public function test_the_branch_scope_can_never_cross_a_tenant(): void
    {
        $this->subscribe();

        $otherBusiness = Business::factory()->create();
        $this->subscribe([], [], $otherBusiness);

        $this->tenant()->setBusiness($otherBusiness);
        $otherBranch = app(BranchService::class)->ensureMainBranch($otherBusiness);
        $otherCounter = PosCounter::factory()->inBranch($otherBranch)->create();

        // Now act as our own business, and deliberately hand the branch context
        // the OTHER tenant's branch id — the tenant scope must still win.
        $this->tenant()->setBusiness($this->business);
        $this->branchContext()->restrictTo([$otherBranch->id]);

        $this->assertNull(PosCounter::query()->find($otherCounter->id));
        $this->assertSame(0, PosCounter::query()->count());
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_manage_branches_over_http(): void
    {
        $this->subscribe();
        app(OrganizationProvisioner::class)->provision($this->business);

        $this->actingAs($this->owner)->get(route('app.branches.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.branches.store'), [
                'name' => 'Harbour Road',
                'code' => 'HARB',
                'city' => 'Karachi',
                'is_active' => true,
            ])
            ->assertRedirect(route('app.branches.index'));

        $this->assertDatabaseHas('branches', [
            'business_id' => $this->business->id,
            'name' => 'Harbour Road',
            'code' => 'HARB',
            'is_main' => false,
        ]);
    }

    public function test_a_cashier_cannot_create_a_branch(): void
    {
        $this->subscribe();
        app(OrganizationProvisioner::class)->provision($this->business);

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::BRANCHES_VIEW])
            ->create();

        $cashier = User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => Branch::query()->forBusiness($this->business->id)->value('id'),
        ]);

        // Viewing is allowed…
        $this->actingAs($cashier)->get(route('app.branches.index'))->assertOk();

        // …creating is not.
        $this->actingAs($cashier)->postJson(route('app.branches.store'), ['name' => 'Mine now'])->assertStatus(403);
        $this->assertDatabaseMissing('branches', ['name' => 'Mine now']);
    }

    public function test_another_businesss_branch_is_not_reachable_over_http(): void
    {
        $this->subscribe();
        app(OrganizationProvisioner::class)->provision($this->business);

        $otherBusiness = Business::factory()->create();
        $otherBranch = Branch::factory()->for($otherBusiness)->create();

        $this->actingAs($this->owner)
            ->get(route('app.branches.edit', $otherBranch))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->put(route('app.branches.update', $otherBranch), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseMissing('branches', ['name' => 'Hijacked']);
    }
}
