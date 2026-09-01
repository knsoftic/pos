<?php

namespace Tests\Feature\Inventory;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Batches and expiry (#34).
 *
 * The two rules these tests exist to protect:
 *
 *   1. FEFO, not FIFO. Stock leaves by earliest EXPIRY, because for perishables
 *      the oldest delivery is not always the one going off first.
 *   2. The batch breakdown and the shelf total move together, always. A
 *      breakdown that disagrees with the total is worse than no breakdown.
 */
class BatchExpiryTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Batch Test Shop']);
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
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
            LimitRegistry::CATEGORIES => 50,
            LimitRegistry::BRANDS => 50,
        ] as $code => $value) {
            $plan->limits()->attach(Limit::query()->where('code', $code)->firstOrFail()->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    protected function perishable(array $overrides = []): Product
    {
        return app(ProductService::class)->create(array_merge([
            'name' => 'Fresh Milk 1L',
            'type' => ProductType::Standard->value,
            'cost_price' => 100,
            'selling_price' => 140,
            'tracks_batches' => true,
        ], $overrides));
    }

    /** Receive a delivery with a lot number and an expiry date. */
    protected function receive(Product $product, float $quantity, ?string $expiry, ?string $lot = null, float $cost = 100): StockMovement
    {
        return $this->inventory()->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'batch_number' => $lot,
            'expiry_date' => $expiry,
        ]);
    }

    // -------------------------------------------------------- opting in

    public function test_batch_tracking_is_off_unless_the_product_asks_for_it(): void
    {
        $this->setUpBusiness();

        $ordinary = app(ProductService::class)->create([
            'name' => 'Phone Charger', 'type' => ProductType::Standard->value, 'selling_price' => 900,
        ]);

        $this->assertFalse($ordinary->tracks_batches);
        $this->assertFalse($this->inventory()->tracksBatches($ordinary));

        $this->receive($ordinary, 5, null);

        $this->assertSame(0, StockBatch::query()->allBranches()->count(),
            'A product that does not track batches must not grow batch rows.');
    }

    public function test_batch_tracking_needs_the_plan_feature(): void
    {
        $this->setUpBusiness([
            FeatureRegistry::INVENTORY_EXPIRY_TRACKING => false,
            FeatureRegistry::CATALOG_BATCH_TRACKING => false,
        ]);

        $product = $this->perishable();

        $this->assertFalse($product->tracks_batches,
            'The flag must not be stored on a plan without the feature — it would spring to life on upgrade.');
    }

    // ------------------------------------------------------------ receiving

    public function test_receiving_creates_a_batch_that_matches_the_shelf(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $movement = $this->receive($product, 12, now()->addDays(10)->toDateString(), 'LOT-A');

        $batch = StockBatch::query()->allBranches()->firstOrFail();

        $this->assertSame('LOT-A', $batch->batch_number);
        $this->assertSame(12.0, (float) $batch->quantity);
        $this->assertSame($batch->id, $movement->stock_batch_id, 'The ledger line points at the batch it filled.');

        // The breakdown and the total agree, which is the whole contract.
        $this->assertSame(12.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_two_deliveries_that_match_are_one_batch(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $expiry = now()->addDays(20)->toDateString();
        $this->receive($product, 5, $expiry, 'LOT-B');
        $this->receive($product, 7, $expiry, 'LOT-B');

        $this->assertSame(1, StockBatch::query()->allBranches()->count());
        $this->assertSame(12.0, (float) StockBatch::query()->allBranches()->value('quantity'));
    }

    public function test_different_expiry_dates_are_different_batches(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->receive($product, 5, now()->addDays(5)->toDateString(), 'LOT-C');
        $this->receive($product, 5, now()->addDays(30)->toDateString(), 'LOT-C');

        $this->assertSame(2, StockBatch::query()->allBranches()->count(),
            'Same lot number, different dates — the shop needs to see them apart.');
    }

    // ----------------------------------------------------------------- FEFO

    public function test_stock_leaves_by_earliest_expiry_not_earliest_delivery(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        // Delivered FIRST but expires LATER — FIFO would take this one.
        $this->receive($product, 10, now()->addDays(30)->toDateString(), 'LONG-LIFE');
        // Delivered SECOND but expires SOONER — FEFO must take this one.
        $this->receive($product, 10, now()->addDays(3)->toDateString(), 'SHORT-LIFE');

        $this->inventory()->issue([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'quantity' => 6,
        ]);

        $short = StockBatch::query()->allBranches()->where('batch_number', 'SHORT-LIFE')->firstOrFail();
        $long = StockBatch::query()->allBranches()->where('batch_number', 'LONG-LIFE')->firstOrFail();

        $this->assertSame(4.0, (float) $short->quantity, 'The soonest-to-expire batch is consumed first.');
        $this->assertSame(10.0, (float) $long->quantity, 'The longer-dated batch is untouched.');
        $this->assertSame(14.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_a_sale_spanning_two_batches_writes_two_ledger_lines(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->receive($product, 4, now()->addDays(3)->toDateString(), 'FIRST', 100);
        $this->receive($product, 10, now()->addDays(30)->toDateString(), 'SECOND', 120);

        $movements = $this->inventory()->issue([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'quantity' => 6,
        ]);

        $this->assertCount(2, $movements, 'One line per batch — each answers how many, from where, at what cost.');
        $this->assertSame(-4.0, (float) $movements[0]->quantity);
        $this->assertSame(-2.0, (float) $movements[1]->quantity);
        $this->assertNotSame($movements[0]->stock_batch_id, $movements[1]->stock_batch_id);

        $this->assertSame(0.0, (float) StockBatch::query()->allBranches()->where('batch_number', 'FIRST')->value('quantity'));
        $this->assertSame(8.0, (float) StockBatch::query()->allBranches()->where('batch_number', 'SECOND')->value('quantity'));
    }

    public function test_undated_batches_are_consumed_last(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->receive($product, 5, null, 'NO-DATE');
        $this->receive($product, 5, now()->addDays(60)->toDateString(), 'DATED');

        $this->inventory()->issue([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'quantity' => 5,
        ]);

        $this->assertSame(5.0, (float) StockBatch::query()->allBranches()->where('batch_number', 'NO-DATE')->value('quantity'),
            'An undated batch is not urgent, so it waits.');
        $this->assertSame(0.0, (float) StockBatch::query()->allBranches()->where('batch_number', 'DATED')->value('quantity'));
    }

    public function test_issue_falls_back_to_a_plain_movement_for_ordinary_products(): void
    {
        $this->setUpBusiness();

        $ordinary = app(ProductService::class)->create([
            'name' => 'Phone Charger', 'type' => ProductType::Standard->value, 'selling_price' => 900,
        ]);

        $this->receive($ordinary, 10, null);

        $movements = $this->inventory()->issue([
            'product' => $ordinary,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'quantity' => 3,
        ]);

        $this->assertCount(1, $movements);
        $this->assertNull($movements[0]->stock_batch_id);
        $this->assertSame(7.0, $this->inventory()->getAvailableStock($ordinary));
    }

    public function test_a_batch_cannot_go_below_zero(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->receive($product, 4, now()->addDays(10)->toDateString(), 'LOT-D');
        $batch = StockBatch::query()->allBranches()->firstOrFail();

        $this->expectException(HttpException::class);

        // Taking six from a batch of four is a mistake, not a negative batch —
        // even where the shelf itself is allowed to go negative.
        config(['inventory.allow_negative_stock' => true]);

        $this->inventory()->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'quantity' => 6,
            'batch_id' => $batch->id,
        ]);
    }

    // ---------------------------------------------------------- the reports

    public function test_expired_and_expiring_are_different_lists(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        // Expired stock cannot be received through the normal path (the form
        // refuses a past date), so it is arranged directly.
        StockBatch::factory()->create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'batch_number' => 'GONE-OFF',
            'expiry_date' => now()->subDays(2)->toDateString(),
            'quantity' => 3,
        ]);

        $this->receive($product, 5, now()->addDays(7)->toDateString(), 'SOON');
        $this->receive($product, 5, now()->addDays(200)->toDateString(), 'FINE');

        $expired = $this->inventory()->expiredBatches()->pluck('batch_number')->all();
        $expiring = $this->inventory()->expiringBatches(30)->pluck('batch_number')->all();

        $this->assertSame(['GONE-OFF'], $expired);
        $this->assertSame(['SOON'], $expiring);
        $this->assertNotContains('FINE', array_merge($expired, $expiring));
    }

    public function test_sellable_stock_excludes_what_has_expired(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        StockBatch::factory()->create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'expiry_date' => now()->subDay()->toDateString(),
            'quantity' => 4,
        ]);

        $this->receive($product, 6, now()->addDays(20)->toDateString(), 'GOOD');

        // On the shelf: 6 (only the received one moved the shelf total).
        // Sellable: 6 as well — the expired 4 must never be counted as sellable.
        $this->assertSame(6.0, $this->inventory()->sellableStock($product));

        // And with everything expired, nothing is sellable even though stock exists.
        StockBatch::query()->allBranches()->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->assertSame(0.0, $this->inventory()->sellableStock($product));
    }

    public function test_batch_status_reads_the_way_a_shopkeeper_would_say_it(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->receive($product, 1, now()->addDays(3)->toDateString(), 'SOON');
        $batch = StockBatch::query()->allBranches()->firstOrFail();

        $this->assertSame(3, $batch->daysUntilExpiry());
        $this->assertTrue($batch->isExpiringSoon());
        $this->assertFalse($batch->isExpired());
        $this->assertSame('Expires in 3d', $batch->statusLabel());
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_expiry_screen_lists_both_groups(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        StockBatch::factory()->create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'batch_number' => 'GONE-OFF',
            'expiry_date' => now()->subDays(2)->toDateString(),
            'quantity' => 2,
        ]);

        $this->receive($product, 5, now()->addDays(5)->toDateString(), 'SOON');

        $this->actingAs($this->owner)
            ->get(route('app.inventory.expiry'))
            ->assertOk()
            ->assertSee('GONE-OFF')
            ->assertSee('SOON')
            ->assertSee('Already expired');
    }

    public function test_the_expiry_screen_needs_the_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::INVENTORY_EXPIRY_TRACKING => false]);

        $this->actingAs($this->owner)
            ->getJson(route('app.inventory.expiry'))
            ->assertStatus(403);
    }

    public function test_an_adjustment_can_carry_batch_details(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->actingAs($this->owner)
            ->post(route('app.inventory.adjust'), [
                'product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'quantity' => 9,
                'reason' => 'Opening delivery',
                'batch_number' => 'LOT-HTTP',
                'expiry_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertRedirect();

        $batch = StockBatch::query()->allBranches()->firstOrFail();

        $this->assertSame('LOT-HTTP', $batch->batch_number);
        $this->assertSame(9.0, (float) $batch->quantity);
        $this->assertSame(9.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_an_expiry_date_in_the_past_is_refused(): void
    {
        $this->setUpBusiness();
        $product = $this->perishable();

        $this->actingAs($this->owner)
            ->post(route('app.inventory.adjust'), [
                'product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'quantity' => 5,
                'reason' => 'Backdated delivery',
                'expiry_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('expiry_date');
    }
}
