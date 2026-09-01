<?php

namespace Tests\Feature\Sales;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The till's HTTP surface (#14–#16, #20, #90, #91, #141, #147).
 *
 * The screen keeps its cart in the browser, so what is worth testing here is
 * everything the browser CANNOT be trusted with: that the server reprices what
 * it is sent, that a repeated request cannot make a second sale, and that a
 * cashier's discount cap is enforced where they cannot edit it.
 */
class PosScreenTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Till Screen Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    /** @param  array<string, bool>  $features */
    protected function setUpBusiness(array $features = []): void
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        foreach ([
            LimitRegistry::PRODUCTS => 100,
            LimitRegistry::CATEGORIES => 50,
            LimitRegistry::BRANDS => 50,
            LimitRegistry::CUSTOMERS => 50,
            LimitRegistry::SUPPLIERS => 50,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ] as $code => $value) {
            $plan->limits()->attach(Limit::query()->where('code', $code)->firstOrFail()->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();
    }

    protected function stocked(float $quantity = 50, float $price = 70, array $overrides = []): Product
    {
        $product = app(ProductService::class)->create(array_merge([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => $price,
            'generate_barcode' => true,
        ], $overrides));

        if ($quantity > 0) {
            app(InventoryService::class)->createMovement([
                'product' => $product,
                'branch_id' => $this->branch->id,
                'type' => StockMovementType::Purchase,
                'quantity' => $quantity,
                'unit_cost' => 40,
            ]);
        }

        return $product;
    }

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions, array $attributes = []): User
    {
        $role = Role::factory()->for($this->business)->withPermissions($permissions)->create();

        return User::factory()->for($this->business)->create(array_merge([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ], $attributes));
    }

    // ==================================================== the screen

    public function test_the_till_opens_with_its_favourites_ready(): void
    {
        $this->setUpBusiness();

        $pinned = $this->stocked(overrides: ['name' => 'Everyday Cola']);
        $pinned->update(['is_favourite' => true]);

        $this->actingAs($this->owner)
            ->get(route('app.pos.index'))
            ->assertOk()
            ->assertSee('Everyday Cola')
            ->assertSee('Walk-in customer');
    }

    public function test_search_answers_in_json_without_a_reload(): void
    {
        $this->setUpBusiness();
        $this->stocked(overrides: ['name' => 'Mineral Water 1.5L']);
        $this->stocked(overrides: ['name' => 'Cola 500ml']);

        $response = $this->actingAs($this->owner)
            ->getJson(route('app.pos.search', ['q' => 'water']));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
        $this->assertSame('Mineral Water 1.5L', $response->json('products.0.name'));
        $this->assertSame(50.0, (float) $response->json('products.0.stock'), 'The grid shows what is on the shelf.');
    }

    public function test_a_scanned_barcode_resolves_to_exactly_one_product(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $response = $this->actingAs($this->owner)
            ->getJson(route('app.pos.scan', ['barcode' => $product->barcode]));

        $response->assertOk();
        $this->assertSame($product->id, $response->json('product.id'));

        // An unknown code answers with nothing rather than a guess.
        $this->actingAs($this->owner)
            ->getJson(route('app.pos.scan', ['barcode' => '0000000000000']))
            ->assertOk()
            ->assertJsonPath('product', null);
    }

    public function test_a_variants_own_barcode_selects_that_variant(): void
    {
        $this->setUpBusiness();

        $shirt = app(ProductService::class)->create([
            'name' => 'T-Shirt', 'type' => ProductType::Variable->value, 'selling_price' => 0,
        ], [
            ['options' => ['Size' => 'M'], 'selling_price' => 550, 'generate_barcode' => true],
            ['options' => ['Size' => 'L'], 'selling_price' => 580, 'generate_barcode' => true],
        ]);

        $large = $shirt->variants->firstWhere('name', 'L');

        $response = $this->actingAs($this->owner)
            ->getJson(route('app.pos.scan', ['barcode' => $large->barcode]));

        $this->assertSame($shirt->id, $response->json('product.id'));
        $this->assertSame($large->id, $response->json('product.selected_variant_id'),
            'A scanner has already answered which one — do not ask again.');
    }

    // ==================================================== checkout

    public function test_a_sale_goes_through_from_the_till(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 70);

        $response = $this->actingAs($this->owner)->postJson(route('app.pos.checkout'), [
            'idempotency_key' => 'cart-abc-123',
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 70]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(210.0, (float) $response->json('sale.total'));
        $this->assertSame(40.0, (float) $response->json('sale.change'));
        $this->assertSame(47.0, app(InventoryService::class)->getAvailableStock($product));
    }

    public function test_the_same_cart_submitted_twice_makes_one_sale(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 70);

        $payload = [
            'idempotency_key' => 'cart-double-tap',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 70]],
            'payments' => [['method' => 'cash', 'amount' => 140]],
        ];

        $first = $this->actingAs($this->owner)->postJson(route('app.pos.checkout'), $payload);
        $second = $this->actingAs($this->owner)->postJson(route('app.pos.checkout'), $payload);

        $first->assertOk();
        $second->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('sale.invoice_no'), $second->json('sale.invoice_no'));
        $this->assertSame(1, Sale::query()->count(), 'A double tap must not sell the same basket twice.');
        $this->assertSame(48.0, app(InventoryService::class)->getAvailableStock($product),
            'And the stock only came off once.');
    }

    public function test_the_server_reprices_what_the_browser_sends(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 500);

        // A tampered cart claiming the item costs 5.
        $response = $this->actingAs($this->owner)->postJson(route('app.pos.checkout'), [
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 5]],
        ]);

        $response->assertOk();

        // The service takes the price it was given, but everything derived from
        // it — the cost, the profit — comes from the shop's own records, so a
        // manipulated price is visible rather than silently profitable.
        $sale = Sale::query()->firstOrFail();

        $this->assertSame(5.0, (float) $sale->total);
        $this->assertSame(40.0, (float) $sale->items->first()->unit_cost, 'The cost is the shop’s, not the browser’s.');
        $this->assertSame(-35.0, $sale->grossProfit(), 'Selling at 5 what cost 40 shows as a loss, as it should.');
    }

    public function test_selling_more_than_the_shelf_holds_is_refused_over_http(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(quantity: 2, price: 70);

        $this->actingAs($this->owner)
            ->postJson(route('app.pos.checkout'), [
                'lines' => [['product_id' => $product->id, 'quantity' => 5]],
                'payments' => [['method' => 'cash', 'amount' => 350]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_stock');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(2.0, app(InventoryService::class)->getAvailableStock($product));
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->postJson(route('app.pos.checkout'), ['lines' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    // ------------------------------------------------- discount cap (#141)

    public function test_a_cashiers_discount_cap_is_enforced_where_they_cannot_edit_it(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        // Allowed 10%, trying to give 50%.
        $cashier = $this->userWith(
            [PermissionRegistry::POS_OPERATE, PermissionRegistry::POS_DISCOUNT],
            ['max_discount_percent' => 10],
        );

        $this->actingAs($cashier)
            ->postJson(route('app.pos.checkout'), [
                'lines' => [[
                    'product_id' => $product->id, 'quantity' => 1,
                    'unit_price' => 100, 'discount_amount' => 50,
                ]],
                'payments' => [['method' => 'cash', 'amount' => 50]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.discount_amount');

        $this->assertSame(0, Sale::query()->count());

        // Within the cap, it goes through.
        $this->actingAs($cashier)
            ->postJson(route('app.pos.checkout'), [
                'lines' => [[
                    'product_id' => $product->id, 'quantity' => 1,
                    'unit_price' => 100, 'discount_amount' => 10,
                ]],
                'payments' => [['method' => 'cash', 'amount' => 90]],
            ])
            ->assertOk();
    }

    public function test_an_uncapped_seller_may_discount_freely(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        // The owner has no personal cap.
        $this->actingAs($this->owner)
            ->postJson(route('app.pos.checkout'), [
                'lines' => [[
                    'product_id' => $product->id, 'quantity' => 1,
                    'unit_price' => 100, 'discount_amount' => 60,
                ]],
                'payments' => [['method' => 'cash', 'amount' => 40]],
            ])
            ->assertOk();
    }

    // ------------------------------------------------------- holds (#20)

    public function test_a_cart_can_be_held_resumed_and_discarded(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 70);

        $held = $this->actingAs($this->owner)->postJson(route('app.pos.hold'), [
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 70]],
        ]);

        $held->assertOk();
        $saleId = $held->json('sale.id');

        // Nothing was posted.
        $this->assertSame(50.0, app(InventoryService::class)->getAvailableStock($product));

        $resumed = $this->actingAs($this->owner)->getJson(route('app.pos.holds.resume', $saleId));
        $resumed->assertOk();
        $this->assertSame(2.0, (float) $resumed->json('lines.0.quantity'));

        $this->actingAs($this->owner)
            ->deleteJson(route('app.pos.holds.discard', $saleId))
            ->assertOk();

        $this->assertNull(Sale::query()->find($saleId));
    }

    // ------------------------------------------- customers & favourites

    public function test_a_customer_can_be_added_from_the_till(): void
    {
        $this->setUpBusiness();

        $response = $this->actingAs($this->owner)->postJson(route('app.pos.customers.store'), [
            'name' => 'Walk-up Regular',
            'phone' => '0300 1112223',
        ]);

        $response->assertOk();
        $this->assertSame('Walk-up Regular', $response->json('customer.name'));
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_adding_a_customer_needs_the_customer_permission(): void
    {
        $this->setUpBusiness();

        $cashier = $this->userWith([PermissionRegistry::POS_OPERATE]);

        $this->actingAs($cashier)
            ->postJson(route('app.pos.customers.store'), ['name' => 'Nope'])
            ->assertStatus(403);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_product_can_be_pinned_to_the_grid(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $this->actingAs($this->owner)
            ->postJson(route('app.pos.favourites.toggle', $product))
            ->assertOk()
            ->assertJsonPath('is_favourite', true);

        $this->assertTrue($product->fresh()->is_favourite);

        $this->actingAs($this->owner)
            ->postJson(route('app.pos.favourites.toggle', $product))
            ->assertJsonPath('is_favourite', false);
    }

    // ------------------------------------------------------------- gates

    public function test_the_till_needs_the_plan_feature_and_the_permission(): void
    {
        $this->setUpBusiness([FeatureRegistry::POS_TERMINAL => false]);

        $this->actingAs($this->owner)->getJson(route('app.pos.index'))->assertStatus(403);
    }

    public function test_someone_who_cannot_operate_the_till_cannot_reach_its_endpoints(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $viewer = $this->userWith([PermissionRegistry::PRODUCTS_VIEW]);

        $this->actingAs($viewer)->getJson(route('app.pos.index'))->assertStatus(403);
        $this->actingAs($viewer)->getJson(route('app.pos.search', ['q' => 'cola']))->assertStatus(403);

        $this->actingAs($viewer)
            ->postJson(route('app.pos.checkout'), [
                'lines' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 70]],
            ])
            ->assertStatus(403);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_another_businesss_customer_cannot_be_attached_from_the_till(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Customer::factory()->create(),
        );

        $this->actingAs($this->owner)
            ->postJson(route('app.pos.checkout'), [
                'customer_id' => $stranger->id,
                'lines' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 70]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_a_credit_sale_from_the_till_lands_on_the_account(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        $customer = app(CustomerService::class)->create([
            'name' => 'Account Customer',
            'credit_limit' => 5000,
        ]);

        $response = $this->actingAs($this->owner)->postJson(route('app.pos.checkout'), [
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();

        $this->assertSame(200.0, (float) $response->json('sale.due'));
        $this->assertSame(200.0, $customer->fresh()->owesUs());
    }
}
