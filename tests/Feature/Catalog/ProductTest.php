<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductType;
use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
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
 * The catalogue itself (#24, #25, #27, #52, #105).
 *
 * The four things that actually matter here:
 *   1. SKUs and barcodes share ONE namespace across products and variants — a
 *      scan at the till must never be ambiguous.
 *   2. A variable product's prices live on its variants; a service can never
 *      carry stock. The type decides, not the form.
 *   3. Cost price is a PERMISSION (#52). Hiding the column is not enough — a
 *      user who cannot see cost must not be able to overwrite it either.
 *   4. Products with history are archived, never destroyed (#104).
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Product Test Shop']);
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

    protected function setUpBusiness(array $features = [], array $limits = []): void
    {
        $this->subscribe($features, $limits);
        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
    }

    protected function products(): ProductService
    {
        return app(ProductService::class);
    }

    /** @return array<string, mixed> */
    protected function validProduct(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 45.50,
            'selling_price' => 70,
            'alert_quantity' => 12,
        ], $overrides);
    }

    /**
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

    // ------------------------------------------------------------- creation

    public function test_a_standard_product_is_created_with_a_generated_sku(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct());

        $this->assertSame($this->business->id, $product->business_id);
        $this->assertSame(ProductType::Standard, $product->type);
        $this->assertNotEmpty($product->sku, 'A product must always have an SKU.');
        $this->assertNotEmpty($product->slug);
        $this->assertNull($product->barcode, 'No barcode unless one is supplied or asked for.');
        $this->assertTrue($product->tracksStock());
        $this->assertSame(35.0, $product->marginPercent());
    }

    public function test_a_supplied_sku_is_honoured_and_uppercased(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct(['sku' => 'cola-500']));

        $this->assertSame('COLA-500', $product->sku);
    }

    public function test_a_duplicate_sku_is_refused(): void
    {
        $this->setUpBusiness();

        $this->products()->create($this->validProduct(['sku' => 'DUP-1']));

        $this->expectException(HttpException::class);

        $this->products()->create($this->validProduct(['name' => 'Another', 'sku' => 'DUP-1']));
    }

    public function test_a_product_sku_cannot_collide_with_a_variant_sku(): void
    {
        $this->setUpBusiness();

        // The whole reason code allocation lives in one service: the two tables
        // share a namespace, so scanning a code is never ambiguous.
        $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'M'], 'sku' => 'SHARED-1', 'selling_price' => 500],
        ]);

        $this->expectException(HttpException::class);

        $this->products()->create($this->validProduct(['name' => 'Mug', 'sku' => 'SHARED-1']));
    }

    public function test_a_generated_barcode_is_a_valid_in_store_ean13(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct(['generate_barcode' => true]));

        $barcode = $product->barcode;

        $this->assertNotNull($barcode);
        $this->assertSame(13, strlen($barcode));
        $this->assertSame('2', $barcode[0], 'In-store codes use the GS1 restricted-circulation prefix.');

        // Verify the check digit the way a scanner would.
        $sum = 0;
        foreach (str_split(substr($barcode, 0, 12)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }
        $this->assertSame((10 - ($sum % 10)) % 10, (int) $barcode[12], 'The EAN-13 check digit is wrong.');
    }

    public function test_a_duplicate_barcode_is_refused(): void
    {
        $this->setUpBusiness();

        $this->products()->create($this->validProduct(['barcode' => '5012345678900']));

        $this->expectException(HttpException::class);

        $this->products()->create($this->validProduct(['name' => 'Other', 'barcode' => '5012345678900']));
    }

    public function test_a_service_can_never_track_stock(): void
    {
        $this->setUpBusiness();

        // The form says yes; the type says no. The type wins.
        $service = $this->products()->create($this->validProduct([
            'name' => 'Delivery',
            'type' => ProductType::Service->value,
            'track_inventory' => true,
        ]));

        $this->assertFalse($service->track_inventory);
        $this->assertFalse($service->tracksStock());
    }

    public function test_a_physical_product_may_opt_out_of_stock(): void
    {
        $this->setUpBusiness();

        $bag = $this->products()->create($this->validProduct([
            'name' => 'Carrier Bag',
            'track_inventory' => false,
        ]));

        $this->assertSame(ProductType::Standard, $bag->type);
        $this->assertFalse($bag->tracksStock());
    }

    public function test_the_product_quota_is_enforced(): void
    {
        $this->setUpBusiness([], [LimitRegistry::PRODUCTS => 2]);

        $this->products()->create($this->validProduct(['name' => 'One']));
        $this->products()->create($this->validProduct(['name' => 'Two']));

        $this->expectException(LimitExceededException::class);
        $this->products()->create($this->validProduct(['name' => 'Three']));
    }

    public function test_a_category_from_another_business_is_refused(): void
    {
        $this->setUpBusiness();

        $stranger = $this->inAnotherBusiness(
            fn (Business $other) => Category::factory()->for($other)->create()
        );

        $this->expectException(HttpException::class);

        $this->products()->create($this->validProduct(['category_id' => $stranger->id]));
    }

    // ------------------------------------------------------------- variants

    public function test_a_variable_product_needs_the_variants_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::CATALOG_VARIANTS => false]);

        $this->expectException(FeatureUnavailableException::class);

        $this->products()->create($this->validProduct(['type' => ProductType::Variable->value]), [
            ['options' => ['Size' => 'M'], 'selling_price' => 500],
        ]);
    }

    public function test_variants_are_created_with_names_built_from_their_options(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'M', 'Colour' => 'Red'], 'cost_price' => 300, 'selling_price' => 550],
            ['options' => ['Size' => 'L', 'Colour' => 'Red'], 'cost_price' => 320, 'selling_price' => 580],
        ]);

        $variants = $product->variants()->get();

        $this->assertCount(2, $variants);
        $this->assertSame('M / Red', $variants->first()->name);
        $this->assertNotSame($variants[0]->sku, $variants[1]->sku, 'Each variant needs its own SKU.');

        // The product's own price columns are meaningless for a variable
        // product, so the range comes from the variants.
        $this->assertSame(['min' => 550.0, 'max' => 580.0], $product->fresh()->load('variants')->priceRange());
    }

    public function test_saving_again_keeps_variant_ids_and_removes_the_ones_dropped(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'M'], 'selling_price' => 550],
            ['options' => ['Size' => 'L'], 'selling_price' => 580],
        ]);

        $medium = $product->variants()->where('name', 'M')->firstOrFail();

        $this->products()->update($product, $this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['id' => $medium->id, 'options' => ['Size' => 'M'], 'selling_price' => 599],
        ]);

        $product->refresh()->load('variants');

        // The kept variant keeps its id, so any history stays attached to it.
        $this->assertCount(1, $product->variants);
        $this->assertSame($medium->id, $product->variants->first()->id);
        $this->assertSame('599.00', $product->variants->first()->selling_price);

        // The dropped one is archived, not destroyed (#198).
        $this->assertSoftDeleted('product_variants', ['name' => 'L']);
    }

    public function test_switching_a_variable_product_to_standard_retires_its_variants(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'M'], 'selling_price' => 550],
        ]);

        $this->products()->update($product, $this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Standard->value,
            'selling_price' => 600,
        ]));

        $this->assertSame(ProductType::Standard, $product->fresh()->type);
        $this->assertSame(0, $product->variants()->count());
        $this->assertSame(1, ProductVariant::withTrashed()->where('product_id', $product->id)->count());
    }

    // ------------------------------------------------------ cost visibility

    public function test_cost_is_hidden_from_a_role_without_view_cost(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct());

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::PRODUCTS_VIEW])
            ->create();
        $staff = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        $response = $this->actingAs($staff)->get(route('app.products.index'));

        $response->assertOk();
        $response->assertSee($product->name);
        $response->assertDontSee('45.50');
        $response->assertSee('Cost prices are hidden for your role.');
    }

    public function test_cost_is_shown_to_a_role_with_view_cost(): void
    {
        $this->setUpBusiness();

        $this->products()->create($this->validProduct());

        $this->actingAs($this->owner)
            ->get(route('app.products.index'))
            ->assertOk()
            ->assertSee('45.50');
    }

    public function test_a_user_who_cannot_see_cost_cannot_overwrite_it(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct());

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::PRODUCTS_VIEW, PermissionRegistry::PRODUCTS_UPDATE])
            ->create();
        $staff = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        // They post the form they were shown — which had no cost field — plus a
        // hand-added cost for good measure. Neither may touch the stored cost.
        $this->actingAs($staff)
            ->put(route('app.products.update', $product), [
                'name' => 'Cola 500ml (renamed)',
                'type' => ProductType::Standard->value,
                'selling_price' => 75,
                'cost_price' => 0,
                'is_active' => true,
                'track_inventory' => true,
            ])
            ->assertRedirect(route('app.products.index'));

        $product->refresh();

        $this->assertSame('Cola 500ml (renamed)', $product->name, 'The edit they were allowed to make should stick.');
        $this->assertSame('45.5000', $product->cost_price, 'A hidden cost was overwritten.');
        $this->assertSame('75.00', $product->selling_price);
    }

    // ---------------------------------------------------- status & lifecycle

    public function test_a_product_can_be_deactivated_and_keeps_its_data(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct());

        $this->products()->setActive($product, false);

        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_product_takes_its_variants_with_it(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'M'], 'selling_price' => 550],
        ]);

        $this->assertTrue($this->products()->delete($product));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSame(0, ProductVariant::where('product_id', $product->id)->count());
    }

    // -------------------------------------------------------------- search

    public function test_search_finds_a_product_by_name_sku_barcode_or_variant(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct([
            'name' => 'Sparkling Water',
            'sku' => 'SPARK-1',
            'barcode' => '5012345678900',
        ]));

        $shirt = $this->products()->create($this->validProduct([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
        ]), [
            ['options' => ['Size' => 'XL'], 'sku' => 'TS-XL', 'selling_price' => 550],
        ]);

        $this->assertTrue(Product::search('Sparkling')->pluck('id')->contains($product->id));
        $this->assertTrue(Product::search('SPARK-1')->pluck('id')->contains($product->id));
        $this->assertTrue(Product::search('5012345678900')->pluck('id')->contains($product->id));

        // A variant's own code finds its parent — that is what a scan at the
        // till has to do.
        $this->assertTrue(Product::search('TS-XL')->pluck('id')->contains($shirt->id));
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_add_a_product_over_http(): void
    {
        $this->setUpBusiness();

        $unit = Unit::query()->where('short_name', 'pc')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('app.products.store'), [
                'name' => 'Chocolate Bar',
                'type' => ProductType::Standard->value,
                'unit_id' => $unit->id,
                'cost_price' => 30,
                'selling_price' => 50,
                'generate_barcode' => true,
                'track_inventory' => true,
                'is_active' => true,
            ])
            ->assertRedirect(route('app.products.index'));

        $product = Product::query()->forBusiness($this->business->id)->where('name', 'Chocolate Bar')->first();

        $this->assertNotNull($product);
        $this->assertNotNull($product->barcode);
        $this->assertSame($unit->id, $product->unit_id);
    }

    public function test_a_variable_product_needs_at_least_one_variant(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.products.store'), [
                'name' => 'Empty Variable',
                'type' => ProductType::Variable->value,
                'selling_price' => 0,
                'variants' => [],
            ])
            ->assertSessionHasErrors('variants');

        $this->assertDatabaseMissing('products', ['name' => 'Empty Variable']);
    }

    public function test_viewing_creating_and_deleting_products_are_separate_authorities(): void
    {
        $this->setUpBusiness();

        $product = $this->products()->create($this->validProduct());

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::PRODUCTS_VIEW])
            ->create();
        $staff = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        $this->actingAs($staff)->get(route('app.products.index'))->assertOk();
        $this->actingAs($staff)->get(route('app.products.create'))->assertRedirect();
        $this->actingAs($staff)->deleteJson(route('app.products.destroy', $product))->assertStatus(403);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_another_businesss_product_is_not_reachable(): void
    {
        $this->setUpBusiness();

        $stranger = $this->inAnotherBusiness(
            fn (Business $other) => Product::factory()->for($other)->create()
        );

        $this->actingAs($this->owner)
            ->get(route('app.products.edit', $stranger))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->put(route('app.products.update', $stranger), [
                'name' => 'Hijacked',
                'type' => ProductType::Standard->value,
                'selling_price' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('products', ['name' => 'Hijacked']);
    }
}
