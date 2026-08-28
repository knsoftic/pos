<?php

namespace Tests\Feature\Inventory;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The inventory engine (#28–#33, #136, #142, #185).
 *
 * What is really being pinned down:
 *   1. THE LEDGER IS THE TRUTH. `stocks.quantity` is a cache maintained in the
 *      same transaction, and `recalculate()` proves the two agree.
 *   2. ONE WRITE PATH. Every change goes through `createMovement()`; the type
 *      decides the sign so no caller has to remember which way a return goes.
 *   3. NEGATIVE STOCK IS A DECISION (#142), defaulting to "no".
 *   4. STOCK IS PER BRANCH (#136), and the branch gate applies to it like
 *      everything else.
 */
class InventoryTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Inventory Test Shop']);
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

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();

        // The owner reaches every branch; without this the branch gate has no
        // opinion and the tests would not exercise it at all.
        app(BranchContext::class)->forUser($this->owner);
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    protected function product(array $overrides = []): Product
    {
        return app(ProductService::class)->create(array_merge([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 70,
        ], $overrides));
    }

    // ------------------------------------------------------- reading stock

    public function test_an_untouched_shelf_reads_as_zero(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product));
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_a_service_never_has_stock(): void
    {
        $this->setUpBusiness();
        $service = $this->product(['name' => 'Delivery', 'type' => ProductType::Service->value]);

        $this->assertSame(0.0, $this->inventory()->getAvailableStock($service));

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $service,
            'type' => StockMovementType::Purchase,
            'quantity' => 5,
        ]);
    }

    // ---------------------------------------------------------- the write

    public function test_a_purchase_adds_stock_and_stamps_the_balance(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $movement = $this->inventory()->createMovement([
            'product' => $product,
            'type' => StockMovementType::Purchase,
            'quantity' => 12,
            'unit_cost' => 40,
        ]);

        $this->assertSame(12.0, (float) $movement->quantity, 'A purchase is a positive movement.');
        $this->assertSame(12.0, (float) $movement->balance_after);
        $this->assertSame(12.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame($this->branch->id, $movement->branch_id);
    }

    public function test_the_type_decides_the_sign_so_callers_never_have_to(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 20, 'unit_cost' => 40,
        ]);

        // The caller passes a positive 5 for a sale; the type makes it −5.
        $sale = $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 5,
        ]);

        $this->assertSame(-5.0, (float) $sale->quantity);
        $this->assertSame(15.0, $this->inventory()->getAvailableStock($product));

        // A customer return puts it back.
        $return = $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::SaleReturn, 'quantity' => 2,
        ]);

        $this->assertSame(2.0, (float) $return->quantity);
        $this->assertSame(17.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_a_zero_movement_is_refused(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Adjustment, 'quantity' => 0,
        ]);
    }

    // --------------------------------------------------- negative stock #142

    public function test_stock_cannot_go_negative_by_default(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 3, 'unit_cost' => 40,
        ]);

        try {
            $this->inventory()->createMovement([
                'product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 5,
            ]);
            $this->fail('A sale was allowed to take the shelf below zero.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(3.0, $e->available);
            $this->assertSame(5.0, $e->requested);
        }

        // And nothing was written — the refusal is not half-applied.
        $this->assertSame(3.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_negative_stock_is_allowed_when_the_business_turns_it_on(): void
    {
        $this->setUpBusiness();
        config(['inventory.allow_negative_stock' => true]);

        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 4,
        ]);

        $this->assertSame(-4.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_has_enough_answers_the_question_the_pos_will_ask(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 2, 'unit_cost' => 40,
        ]);

        $this->assertTrue($this->inventory()->hasEnough($product, 2));
        $this->assertFalse($this->inventory()->hasEnough($product, 3));

        config(['inventory.allow_negative_stock' => true]);
        $this->assertTrue($this->inventory()->hasEnough($product, 3), 'With negatives allowed, there is always enough.');
    }

    // ------------------------------------------------------------ costing

    public function test_incoming_stock_re_weights_the_average_cost(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        // 10 @ 40 then 10 @ 60 → average 50.
        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);
        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 60,
        ]);

        $stock = Stock::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('50.0000', $stock->average_cost);
        $this->assertSame(1000.0, $stock->value(), '20 units at an average of 50.');
    }

    public function test_selling_does_not_change_the_average_cost(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);
        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 4,
        ]);

        $stock = Stock::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('40.0000', $stock->average_cost, 'A sale consumes value at the cost on the books.');
        $this->assertSame(240.0, $stock->value());
    }

    public function test_a_movement_with_no_cost_falls_back_to_the_catalogue_price(): void
    {
        $this->setUpBusiness();
        $product = $this->product(['cost_price' => 33]);

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 5,
        ]);

        $stock = Stock::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('33.0000', $stock->average_cost, 'Stock must never be valued at zero by accident.');
    }

    // --------------------------------------------------------- adjustments

    public function test_an_adjustment_goes_either_way_and_is_audited(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);

        $found = $this->inventory()->adjust($product, 3, 'Found a box out back');
        $lost = $this->inventory()->adjust($product, -2, 'Damaged in transit');

        $this->assertSame(3.0, (float) $found->quantity);
        $this->assertSame(-2.0, (float) $lost->quantity);
        $this->assertSame(11.0, $this->inventory()->getAvailableStock($product));

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $this->business->id,
            'event' => 'stock.adjusted',
        ]);
    }

    public function test_a_stock_take_posts_the_difference_not_the_total(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);

        // Counted 7 where the system said 10.
        $movement = $this->inventory()->setStockTo($product, 7, 'Monthly count');

        $this->assertNotNull($movement);
        $this->assertSame(-3.0, (float) $movement->quantity, 'The ledger records what changed, not what was counted.');
        $this->assertSame(7.0, $this->inventory()->getAvailableStock($product));

        // Counting the same figure again writes nothing at all.
        $this->assertNull($this->inventory()->setStockTo($product, 7, 'Monthly count'));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_opening_stock_is_recorded_once(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $first = $this->inventory()->recordOpeningStock($product, 25, 38);
        $second = $this->inventory()->recordOpeningStock($product, 99, 38);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second opening entry would be a correction, not an opening.');
        $this->assertSame(25.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(StockMovementType::Opening, $first->type);
    }

    // ------------------------------------------------------------- variants

    public function test_a_variable_product_keeps_stock_per_variant(): void
    {
        $this->setUpBusiness();

        $shirt = app(ProductService::class)->create([
            'name' => 'T-Shirt',
            'type' => ProductType::Variable->value,
            'selling_price' => 0,
        ], [
            ['options' => ['Size' => 'M'], 'cost_price' => 300, 'selling_price' => 550],
            ['options' => ['Size' => 'L'], 'cost_price' => 320, 'selling_price' => 580],
        ]);

        [$medium, $large] = $shirt->variants()->orderBy('id')->get()->all();

        $this->inventory()->createMovement([
            'product' => $shirt, 'variant_id' => $medium->id, 'type' => StockMovementType::Purchase, 'quantity' => 6, 'unit_cost' => 300,
        ]);
        $this->inventory()->createMovement([
            'product' => $shirt, 'variant_id' => $large->id, 'type' => StockMovementType::Purchase, 'quantity' => 4, 'unit_cost' => 320,
        ]);

        $this->assertSame(6.0, $this->inventory()->getAvailableStock($shirt, $medium->id));
        $this->assertSame(4.0, $this->inventory()->getAvailableStock($shirt, $large->id));

        // Across the whole product, with no variant named, it is the total.
        $this->assertSame(10.0, $this->inventory()->getAvailableStock($shirt));
    }

    public function test_a_variable_product_needs_a_variant_named(): void
    {
        $this->setUpBusiness();

        $shirt = app(ProductService::class)->create([
            'name' => 'T-Shirt', 'type' => ProductType::Variable->value, 'selling_price' => 0,
        ], [
            ['options' => ['Size' => 'M'], 'selling_price' => 550],
        ]);

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $shirt, 'type' => StockMovementType::Purchase, 'quantity' => 3,
        ]);
    }

    public function test_a_variant_from_another_product_is_refused(): void
    {
        $this->setUpBusiness();

        $shirt = app(ProductService::class)->create([
            'name' => 'T-Shirt', 'type' => ProductType::Variable->value, 'selling_price' => 0,
        ], [['options' => ['Size' => 'M'], 'selling_price' => 550]]);

        $mug = app(ProductService::class)->create([
            'name' => 'Mug', 'type' => ProductType::Variable->value, 'selling_price' => 0,
        ], [['options' => ['Size' => 'Large'], 'selling_price' => 300]]);

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $shirt,
            'variant_id' => $mug->variants()->value('id'),
            'type' => StockMovementType::Purchase,
            'quantity' => 1,
        ]);
    }

    // --------------------------------------------------- ledger & recalcuate

    public function test_the_ledger_reads_newest_first_with_a_running_balance(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40]);
        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 3]);
        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 2]);

        $ledger = $this->inventory()->ledger($product->id)->get();

        $this->assertCount(3, $ledger);
        $this->assertSame([5.0, 7.0, 10.0], $ledger->pluck('balance_after')->map(fn ($b) => (float) $b)->all());
        $this->assertSame(5.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_recalculate_rebuilds_the_balance_from_the_ledger(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40]);
        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Sale, 'quantity' => 4]);

        // Corrupt the cache behind the service's back, the way a bad import or a
        // half-finished migration would.
        Stock::query()->where('product_id', $product->id)->update(['quantity' => 999]);

        $result = $this->inventory()->recalculate($this->branch->id, $product->id);

        $this->assertTrue($result['drifted'], 'A corrupted balance must be reported, not silently fixed.');
        $this->assertSame(999.0, $result['before']);
        $this->assertSame(6.0, $result['after']);
        $this->assertSame(6.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_a_healthy_shelf_reports_no_drift(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 7, 'unit_cost' => 40]);

        $result = $this->inventory()->recalculate($this->branch->id, $product->id);

        $this->assertFalse($result['drifted'], 'The cache and the ledger must agree after every write.');
    }

    // ------------------------------------------------------- low stock #33

    public function test_low_stock_uses_the_products_own_threshold(): void
    {
        $this->setUpBusiness();

        $low = $this->product(['name' => 'Nearly out', 'alert_quantity' => 10]);
        $fine = $this->product(['name' => 'Plenty', 'alert_quantity' => 2]);
        $unwatched = $this->product(['name' => 'No threshold', 'alert_quantity' => null]);

        foreach ([[$low, 4], [$fine, 40], [$unwatched, 1]] as [$product, $quantity]) {
            $this->inventory()->createMovement([
                'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => $quantity, 'unit_cost' => 40,
            ]);
        }

        $names = $this->inventory()->lowStock()->get()->map(fn (Stock $s) => $s->product->name)->all();

        $this->assertContains('Nearly out', $names);
        $this->assertNotContains('Plenty', $names);
        $this->assertNotContains('No threshold', $names, 'Silence is right for a product nobody asked to watch.');
    }

    public function test_a_config_fallback_threshold_widens_the_net(): void
    {
        $this->setUpBusiness();
        config(['inventory.default_alert_quantity' => 5]);

        $product = $this->product(['name' => 'No threshold', 'alert_quantity' => null]);

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 3, 'unit_cost' => 40,
        ]);

        $this->assertSame(1, $this->inventory()->lowStock()->count());
    }

    // ------------------------------------------------------- valuation #28

    public function test_valuation_totals_what_is_on_hand(): void
    {
        $this->setUpBusiness();

        $a = $this->product(['name' => 'A']);
        $b = $this->product(['name' => 'B', 'alert_quantity' => 100]);

        $this->inventory()->createMovement(['product' => $a, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 50]);
        $this->inventory()->createMovement(['product' => $b, 'type' => StockMovementType::Purchase, 'quantity' => 4, 'unit_cost' => 25]);

        $valuation = $this->inventory()->valuation();

        $this->assertSame(14.0, $valuation['quantity']);
        $this->assertSame(600.0, $valuation['value'], '10 × 50 + 4 × 25');
        $this->assertSame(2, $valuation['shelves']);
        $this->assertSame(1, $valuation['low'], 'B is under its threshold of 100.');
    }

    // ------------------------------------------------- branches & tenancy

    public function test_stock_is_kept_per_branch(): void
    {
        $this->setUpBusiness();

        $second = app(BranchService::class)->create(['name' => 'Second Shop']);
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $this->branch->id, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);
        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $second->id, 'type' => StockMovementType::Purchase, 'quantity' => 3, 'unit_cost' => 40,
        ]);

        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product, null, $this->branch->id));
        $this->assertSame(3.0, $this->inventory()->getAvailableStock($product, null, $second->id));

        $byBranch = $this->inventory()->stockByBranch($product);
        $this->assertSame(10.0, $byBranch[$this->branch->id]['quantity']);
        $this->assertSame(3.0, $byBranch[$second->id]['quantity']);
    }

    public function test_a_cashier_only_sees_their_own_branchs_stock(): void
    {
        $this->setUpBusiness();

        $second = app(BranchService::class)->create(['name' => 'Second Shop']);
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $this->branch->id, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);
        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $second->id, 'type' => StockMovementType::Purchase, 'quantity' => 3, 'unit_cost' => 40,
        ]);

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $this->branch->id]);
        app(BranchContext::class)->forUser($cashier);

        // Their own branch is all they can see, and all they can total.
        $this->assertSame(1, Stock::query()->count());
        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_a_movement_into_an_unreachable_branch_is_refused(): void
    {
        $this->setUpBusiness();

        $second = app(BranchService::class)->create(['name' => 'Second Shop']);
        $product = $this->product();

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $this->branch->id]);
        app(BranchContext::class)->forUser($cashier);

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $second->id, 'type' => StockMovementType::Purchase, 'quantity' => 1,
        ]);
    }

    // --------------------------------------------------------- the feature

    public function test_stock_tracking_can_be_absent_from_the_plan(): void
    {
        $this->setUpBusiness([FeatureRegistry::INVENTORY_STOCK_TRACKING => false]);

        $product = $this->product();

        $this->assertFalse($this->inventory()->isTrackingEnabled());

        $this->expectException(HttpException::class);

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 5,
        ]);
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_owner_can_see_the_inventory_screen_and_adjust_stock(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);

        $this->actingAs($this->owner)->get(route('app.inventory.index'))->assertOk()->assertSee('Cola 500ml');

        $this->actingAs($this->owner)
            ->post(route('app.inventory.adjust'), [
                'product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'quantity' => -2,
                'reason' => 'Damaged',
            ])
            ->assertRedirect();

        $this->assertSame(8.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_adjusting_stock_needs_its_own_permission(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement([
            'product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40,
        ]);

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::INVENTORY_VIEW])
            ->create();

        $viewer = User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ]);

        // Looking is allowed…
        $this->actingAs($viewer)->get(route('app.inventory.index'))->assertOk();

        // …changing is not.
        $this->actingAs($viewer)
            ->postJson(route('app.inventory.adjust'), [
                'product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'quantity' => 50,
                'reason' => 'Sneaky',
            ])
            ->assertStatus(403);

        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_the_ledger_screen_shows_a_products_history(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->inventory()->createMovement(['product' => $product, 'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 40]);
        $this->inventory()->adjust($product, -1, 'Broken bottle');

        $this->actingAs($this->owner)
            ->get(route('app.inventory.ledger', $product))
            ->assertOk()
            ->assertSee('Broken bottle')
            ->assertSee('Purchase');
    }

    public function test_another_businesss_stock_is_not_reachable(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Product::factory()->create(),
        );

        $this->actingAs($this->owner)
            ->get(route('app.inventory.ledger', $stranger))
            ->assertNotFound();
    }
}
