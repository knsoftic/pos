<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\OrganizationProvisioner;
use App\Services\PlanLimitService;
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
 * The lists products are filed under: categories, brands and units (#26, #158).
 *
 * Three rules are being pinned down:
 *   QUOTAS  — categories and brands are metered; units deliberately are not.
 *   ARCHIVE — anything a product points at is deactivated, never deleted (#104).
 *   TENANT  — a parent or base unit from another business simply is not found.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Catalog Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<string, bool>  $features
     * @param  array<string, int|null>  $limits
     */
    protected function subscribe(array $features = [], array $limits = []): Plan
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        $limits = $limits + [
            LimitRegistry::PRODUCTS => 100,
            LimitRegistry::CATEGORIES => 50,
            LimitRegistry::BRANDS => 50,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ];

        foreach ($limits as $code => $value) {
            $limit = Limit::query()->where('code', $code)->firstOrFail();
            $plan->limits()->attach($limit->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        return $plan;
    }

    /** Subscribe, provision, and enter the tenant context. */
    protected function setUpBusiness(array $features = [], array $limits = []): void
    {
        $this->subscribe($features, $limits);
        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
    }

    protected function catalog(): CatalogService
    {
        return app(CatalogService::class);
    }

    /**
     * Build a fixture that really belongs to another business — see the same
     * helper in EmployeeTest for why this matters.
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

    // ---------------------------------------------------------- provisioning

    public function test_a_new_business_starts_with_one_base_unit(): void
    {
        $this->setUpBusiness();

        $units = Unit::query()->forBusiness($this->business->id)->get();

        $this->assertCount(1, $units);
        $this->assertSame('pc', $units->first()->short_name);
        $this->assertTrue($units->first()->isBaseUnit());
    }

    public function test_provisioning_twice_does_not_duplicate_the_unit(): void
    {
        $this->setUpBusiness();
        app(OrganizationProvisioner::class)->provision($this->business);

        $this->assertSame(1, Unit::query()->forBusiness($this->business->id)->count());
    }

    // ------------------------------------------------------------ categories

    public function test_a_category_can_hold_subcategories(): void
    {
        $this->setUpBusiness();

        $drinks = $this->catalog()->createCategory(['name' => 'Drinks']);
        $cold = $this->catalog()->createCategory(['name' => 'Cold Drinks', 'parent_id' => $drinks->id]);

        $this->assertSame($drinks->id, $cold->parent_id);
        $this->assertSame('Drinks → Cold Drinks', $cold->pathName());
        $this->assertTrue($drinks->children()->whereKey($cold->id)->exists());
    }

    public function test_a_category_cannot_be_its_own_parent_or_sit_under_its_own_child(): void
    {
        $this->setUpBusiness();

        $parent = $this->catalog()->createCategory(['name' => 'Parent']);
        $child = $this->catalog()->createCategory(['name' => 'Child', 'parent_id' => $parent->id]);

        // Self-parenting.
        try {
            $this->catalog()->updateCategory($parent, ['name' => 'Parent', 'parent_id' => $parent->id]);
            $this->fail('A category was allowed to be its own parent.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // A ring: parent under its own child.
        try {
            $this->catalog()->updateCategory($parent, ['name' => 'Parent', 'parent_id' => $child->id]);
            $this->fail('A category was allowed to sit under its own subcategory.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_a_parent_from_another_business_is_refused(): void
    {
        $this->setUpBusiness();

        $stranger = $this->inAnotherBusiness(
            fn (Business $other) => Category::factory()->for($other)->create()
        );

        $this->expectException(HttpException::class);

        $this->catalog()->createCategory(['name' => 'Mine', 'parent_id' => $stranger->id]);
    }

    public function test_a_category_holding_products_is_not_deleted(): void
    {
        $this->setUpBusiness();

        $category = $this->catalog()->createCategory(['name' => 'Snacks']);
        Product::factory()->for($this->business)->create(['category_id' => $category->id]);

        $this->assertFalse($this->catalog()->deleteCategory($category));
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_a_category_holding_subcategories_is_not_deleted(): void
    {
        $this->setUpBusiness();

        $parent = $this->catalog()->createCategory(['name' => 'Parent']);
        $this->catalog()->createCategory(['name' => 'Child', 'parent_id' => $parent->id]);

        $this->assertFalse($this->catalog()->deleteCategory($parent));
    }

    public function test_an_empty_category_is_deleted(): void
    {
        $this->setUpBusiness();

        $category = $this->catalog()->createCategory(['name' => 'Temporary']);

        $this->assertTrue($this->catalog()->deleteCategory($category));
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_the_category_quota_is_enforced(): void
    {
        $this->setUpBusiness([], [LimitRegistry::CATEGORIES => 2]);

        $this->catalog()->createCategory(['name' => 'One']);
        $this->catalog()->createCategory(['name' => 'Two']);

        $this->expectException(LimitExceededException::class);
        $this->catalog()->createCategory(['name' => 'Three']);
    }

    public function test_slugs_stay_unique_even_for_identical_names(): void
    {
        $this->setUpBusiness();

        $first = $this->catalog()->createCategory(['name' => 'Drinks']);
        $second = $this->catalog()->createCategory(['name' => 'Drinks']);

        $this->assertNotSame($first->slug, $second->slug);
    }

    // ---------------------------------------------------------------- brands

    public function test_a_brand_in_use_is_not_deleted(): void
    {
        $this->setUpBusiness();

        $brand = $this->catalog()->createBrand(['name' => 'Acme']);
        Product::factory()->for($this->business)->create(['brand_id' => $brand->id]);

        $this->assertFalse($this->catalog()->deleteBrand($brand));
    }

    public function test_the_brand_quota_is_enforced(): void
    {
        $this->setUpBusiness([], [LimitRegistry::BRANDS => 1]);

        $this->catalog()->createBrand(['name' => 'First']);

        $this->expectException(LimitExceededException::class);
        $this->catalog()->createBrand(['name' => 'Second']);
    }

    // ----------------------------------------------------------------- units

    public function test_a_base_unit_needs_no_feature_and_converts_to_itself(): void
    {
        $this->setUpBusiness([FeatureRegistry::CATALOG_MULTI_UNIT => false]);

        $unit = $this->catalog()->createUnit(['name' => 'Litre', 'short_name' => 'ltr', 'allows_decimals' => true]);

        $this->assertTrue($unit->isBaseUnit());
        $this->assertSame(1.0, $unit->factor());
        $this->assertSame(3.0, $unit->toBase(3));
    }

    public function test_a_derived_unit_needs_the_multi_unit_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::CATALOG_MULTI_UNIT => false]);

        $piece = Unit::query()->where('short_name', 'pc')->firstOrFail();

        $this->expectException(FeatureUnavailableException::class);

        $this->catalog()->createUnit([
            'name' => 'Dozen',
            'short_name' => 'dz',
            'base_unit_id' => $piece->id,
            'conversion_factor' => 12,
        ]);
    }

    public function test_a_derived_unit_converts_both_ways(): void
    {
        $this->setUpBusiness();

        $piece = Unit::query()->where('short_name', 'pc')->firstOrFail();

        $dozen = $this->catalog()->createUnit([
            'name' => 'Dozen',
            'short_name' => 'dz',
            'base_unit_id' => $piece->id,
            'conversion_factor' => 12,
        ]);

        $this->assertFalse($dozen->isBaseUnit());
        $this->assertSame(24.0, $dozen->toBase(2), 'Two dozen is 24 pieces.');
        $this->assertSame(2.0, $dozen->fromBase(24), 'Twenty-four pieces is two dozen.');
    }

    public function test_a_conversion_chain_is_refused(): void
    {
        $this->setUpBusiness();

        $piece = Unit::query()->where('short_name', 'pc')->firstOrFail();
        $dozen = $this->catalog()->createUnit([
            'name' => 'Dozen', 'short_name' => 'dz', 'base_unit_id' => $piece->id, 'conversion_factor' => 12,
        ]);

        // Gross → Dozen → Piece is a chain nothing is ready to walk.
        $this->expectException(HttpException::class);

        $this->catalog()->createUnit([
            'name' => 'Gross', 'short_name' => 'gr', 'base_unit_id' => $dozen->id, 'conversion_factor' => 12,
        ]);
    }

    public function test_a_zero_or_negative_conversion_factor_is_refused(): void
    {
        $this->setUpBusiness();

        $piece = Unit::query()->where('short_name', 'pc')->firstOrFail();

        $this->expectException(HttpException::class);

        $this->catalog()->createUnit([
            'name' => 'Broken', 'short_name' => 'bk', 'base_unit_id' => $piece->id, 'conversion_factor' => 0,
        ]);
    }

    public function test_units_are_not_metered(): void
    {
        $this->setUpBusiness();

        // Fifteen units, no quota to hit — describing goods accurately must not
        // cost a tenant anything.
        for ($i = 0; $i < 15; $i++) {
            $this->catalog()->createUnit(['name' => 'Unit '.$i, 'short_name' => 'u'.$i]);
        }

        $this->assertSame(16, Unit::query()->forBusiness($this->business->id)->count());
    }

    public function test_a_unit_in_use_is_not_deleted(): void
    {
        $this->setUpBusiness();

        $unit = Unit::query()->where('short_name', 'pc')->firstOrFail();
        Product::factory()->for($this->business)->create(['unit_id' => $unit->id]);

        $this->assertFalse($this->catalog()->deleteUnit($unit));
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_manage_the_catalogue_lists_over_http(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->get(route('app.categories.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('app.brands.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('app.units.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.categories.store'), ['name' => 'Bakery', 'is_active' => true])
            ->assertRedirect(route('app.categories.index'));

        $this->actingAs($this->owner)
            ->post(route('app.brands.store'), ['name' => 'Local Farm', 'is_active' => true])
            ->assertRedirect(route('app.brands.index'));

        $this->assertDatabaseHas('categories', ['business_id' => $this->business->id, 'name' => 'Bakery']);
        $this->assertDatabaseHas('brands', ['business_id' => $this->business->id, 'name' => 'Local Farm']);
    }

    public function test_a_role_without_catalog_manage_cannot_reach_the_lists(): void
    {
        $this->setUpBusiness();

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::PRODUCTS_VIEW])
            ->create();

        $staff = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        $this->actingAs($staff)->get(route('app.categories.index'))->assertRedirect();
        $this->actingAs($staff)->postJson(route('app.brands.store'), ['name' => 'Sneaky'])->assertStatus(403);

        $this->assertDatabaseMissing('brands', ['name' => 'Sneaky']);
    }

    public function test_another_businesss_category_is_not_reachable(): void
    {
        $this->setUpBusiness();

        $stranger = $this->inAnotherBusiness(
            fn (Business $other) => Category::factory()->for($other)->create()
        );

        $this->actingAs($this->owner)
            ->get(route('app.categories.edit', $stranger))
            ->assertNotFound();
    }

    public function test_catalogue_usage_feeds_the_meters(): void
    {
        $this->setUpBusiness();

        $this->catalog()->createCategory(['name' => 'One']);
        $this->catalog()->createCategory(['name' => 'Two']);
        $this->catalog()->createBrand(['name' => 'Brand']);
        Product::factory()->count(3)->for($this->business)->create();

        $limits = app(PlanLimitService::class);
        $limits->flush();

        $this->assertSame(2, $limits->usage(LimitRegistry::CATEGORIES));
        $this->assertSame(1, $limits->usage(LimitRegistry::BRANDS));
        $this->assertSame(3, $limits->usage(LimitRegistry::PRODUCTS));
    }
}
