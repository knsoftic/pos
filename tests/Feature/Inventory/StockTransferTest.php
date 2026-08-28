<?php

namespace Tests\Feature\Inventory;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\FeatureUnavailableException;
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
use App\Models\StockTransfer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\StockTransferService;
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
 * Stock transfers between branches (#32).
 *
 * The thing worth pinning down is the JOURNEY: goods leave one shelf before
 * they reach another, and if eleven leave and ten arrive, the eleventh must be
 * visible somewhere. These tests exist mostly to stop a future refactor from
 * "helpfully" reconciling that away.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $main;

    protected Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Transfer Test Shop']);
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

        $this->main = Branch::query()->forBusiness($this->business->id)->where('is_main', true)->firstOrFail();
        $this->second = app(BranchService::class)->create(['name' => 'Depot']);
    }

    protected function transfers(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    /** A product with $quantity already on the main branch's shelf. */
    protected function stockedProduct(float $quantity = 20, array $overrides = []): Product
    {
        $product = app(ProductService::class)->create(array_merge([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 70,
        ], $overrides));

        if ($quantity > 0) {
            $this->inventory()->createMovement([
                'product' => $product,
                'branch_id' => $this->main->id,
                'type' => StockMovementType::Purchase,
                'quantity' => $quantity,
                'unit_cost' => 40,
            ]);
        }

        return $product;
    }

    protected function draft(Product $product, float $quantity = 5): StockTransfer
    {
        return $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->second->id,
        ], [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ]);
    }

    // ----------------------------------------------------------- the journey

    public function test_a_draft_moves_no_stock_at_all(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);

        $transfer = $this->draft($product, 5);

        $this->assertSame(TransferStatus::Draft, $transfer->status);
        $this->assertSame('TRF-000001', $transfer->reference);
        $this->assertSame(20.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
    }

    public function test_sending_takes_stock_off_the_source_and_puts_it_nowhere(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->send($transfer);

        $this->assertSame(TransferStatus::Sent, $transfer->fresh()->status);
        $this->assertSame(15.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));

        // In transit: off one shelf, not yet on the other. That is the point.
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
    }

    public function test_receiving_puts_the_stock_on_the_destination_shelf(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->send($transfer);
        $this->transfers()->receive($transfer);

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(15.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));
        $this->assertSame(5.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
        $this->assertFalse($transfer->fresh()->load('items')->hasShortfall());
    }

    public function test_the_cost_travels_with_the_goods(): void
    {
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value, 'cost_price' => 40, 'selling_price' => 70,
        ]);

        // Bought at 55, not at the catalogue's 40.
        $this->inventory()->createMovement([
            'product' => $product, 'branch_id' => $this->main->id,
            'type' => StockMovementType::Purchase, 'quantity' => 10, 'unit_cost' => 55,
        ]);

        $transfer = $this->draft($product, 4);
        $this->transfers()->send($transfer);
        $this->transfers()->receive($transfer);

        $destinationStock = Stock::query()
            ->allBranches()
            ->where('branch_id', $this->second->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('55.0000', $destinationStock->average_cost,
            'The receiving branch values stock at what it actually cost, not the catalogue price.');
    }

    // ------------------------------------------------------- the shortfall

    public function test_a_shortfall_is_recorded_not_reconciled(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 11);

        $this->transfers()->send($transfer);

        $itemId = $transfer->items()->value('id');
        $this->transfers()->receive($transfer, [$itemId => 10]);

        $transfer = $transfer->fresh()->load('items');

        $this->assertTrue($transfer->hasShortfall());
        $this->assertSame(1.0, $transfer->shortfall());
        $this->assertSame(11.0, $transfer->totalSent());
        $this->assertSame(10.0, $transfer->totalReceived());

        // 20 − 11 left the source; only 10 landed. The missing unit is simply
        // not on any shelf, which is the truth.
        $this->assertSame(9.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));
        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
    }

    public function test_a_line_where_nothing_arrived_posts_no_movement(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 6);

        $this->transfers()->send($transfer);
        $this->transfers()->receive($transfer, [$transfer->items()->value('id') => 0]);

        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
        $this->assertSame(0, StockMovement::query()
            ->allBranches()
            ->where('type', StockMovementType::TransferIn)
            ->count());
    }

    // ------------------------------------------------------------- refusals

    public function test_a_transfer_cannot_send_more_than_is_on_the_shelf(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(3);
        $transfer = $this->draft($product, 5);

        try {
            $this->transfers()->send($transfer);
            $this->fail('A transfer was allowed to send stock that does not exist.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(3.0, $e->available);
        }

        // Rolled back completely: still a draft, shelf untouched.
        $this->assertSame(TransferStatus::Draft, $transfer->fresh()->status);
        $this->assertSame(3.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));
    }

    public function test_a_transfer_needs_two_different_branches(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(10);

        $this->expectException(HttpException::class);

        $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->main->id,
        ], [['product_id' => $product->id, 'quantity' => 1]]);
    }

    public function test_the_same_product_cannot_appear_twice(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(10);

        $this->expectException(HttpException::class);

        $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->second->id,
        ], [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 3],
        ]);
    }

    public function test_a_service_cannot_be_transferred(): void
    {
        $this->setUpBusiness();

        $service = app(ProductService::class)->create([
            'name' => 'Delivery', 'type' => ProductType::Service->value, 'selling_price' => 150,
        ]);

        $this->expectException(HttpException::class);

        $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->second->id,
        ], [['product_id' => $service->id, 'quantity' => 1]]);
    }

    public function test_a_sent_transfer_cannot_be_edited(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->send($transfer);

        $this->expectException(HttpException::class);

        $this->transfers()->update($transfer, [], [['product_id' => $product->id, 'quantity' => 9]]);
    }

    public function test_a_received_transfer_cannot_be_cancelled(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->send($transfer);
        $this->transfers()->receive($transfer);

        $this->expectException(HttpException::class);

        $this->transfers()->cancel($transfer, 'Changed my mind');
    }

    public function test_transfers_can_be_absent_from_the_plan(): void
    {
        $this->setUpBusiness([FeatureRegistry::INVENTORY_TRANSFERS => false]);
        $product = $this->stockedProduct(10);

        $this->expectException(FeatureUnavailableException::class);

        $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->second->id,
        ], [['product_id' => $product->id, 'quantity' => 1]]);
    }

    // ----------------------------------------------------------- cancelling

    public function test_cancelling_a_draft_costs_nothing(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->cancel($transfer, 'Not needed after all');

        $this->assertSame(TransferStatus::Cancelled, $transfer->fresh()->status);
        $this->assertSame(20.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));
        $this->assertSame(0, StockMovement::query()->allBranches()->where('type', StockMovementType::TransferOut)->count());
    }

    public function test_cancelling_a_sent_transfer_puts_the_stock_back(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);

        $this->transfers()->send($transfer);
        $this->assertSame(15.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));

        $this->transfers()->cancel($transfer, 'Van broke down');

        $this->assertSame(TransferStatus::Cancelled, $transfer->fresh()->status);
        $this->assertSame(20.0, $this->inventory()->getAvailableStock($product, null, $this->main->id));

        // The round trip is visible in the ledger rather than erased.
        $this->assertSame(2, StockMovement::query()->allBranches()->whereIn('type', [
            StockMovementType::TransferOut->value,
            StockMovementType::TransferIn->value,
        ])->count());
    }

    // -------------------------------------------------------- branch access

    public function test_you_can_only_send_from_a_branch_you_can_reach(): void
    {
        $this->setUpBusiness();
        $this->stockedProduct(20);

        $product = Product::query()->firstOrFail();

        $depotOnly = User::factory()->for($this->business)->create(['branch_id' => $this->second->id]);
        app(BranchContext::class)->forUser($depotOnly);

        $this->expectException(HttpException::class);

        $this->transfers()->create([
            'from_branch_id' => $this->main->id,
            'to_branch_id' => $this->second->id,
        ], [['product_id' => $product->id, 'quantity' => 1]]);
    }

    public function test_receiving_belongs_to_the_destination_branch(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);
        $this->transfers()->send($transfer);

        // Someone who only works at the SOURCE cannot count goods in at the
        // destination — they are not standing there.
        $mainOnly = User::factory()->for($this->business)->create(['branch_id' => $this->main->id]);
        app(BranchContext::class)->forUser($mainOnly);

        $this->expectException(HttpException::class);

        $this->transfers()->receive($transfer);
    }

    public function test_only_the_sending_branch_can_cancel_goods_in_transit(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);
        $this->transfers()->send($transfer);

        $depotOnly = User::factory()->for($this->business)->create(['branch_id' => $this->second->id]);
        app(BranchContext::class)->forUser($depotOnly);

        $this->expectException(HttpException::class);

        $this->transfers()->cancel($transfer, 'Not mine to cancel');
    }

    public function test_a_manager_sees_transfers_arriving_at_their_branch(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);
        $transfer = $this->draft($product, 5);
        $this->transfers()->send($transfer);

        $depotOnly = User::factory()->for($this->business)->create(['branch_id' => $this->second->id]);
        app(BranchContext::class)->forUser($depotOnly);

        $visible = StockTransfer::query()
            ->visibleTo(app(BranchContext::class)->branchIds())
            ->pluck('id');

        $this->assertContains($transfer->id, $visible->all(),
            'A branch must see what is coming to it, not only what it sent.');
    }

    // ------------------------------------------------------------ over HTTP

    public function test_the_whole_flow_works_over_http(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(20);

        $this->actingAs($this->owner)
            ->post(route('app.transfers.store'), [
                'from_branch_id' => $this->main->id,
                'to_branch_id' => $this->second->id,
                'items' => [['product_id' => $product->id, 'quantity' => 6]],
            ])
            ->assertRedirect();

        $transfer = StockTransfer::query()->firstOrFail();

        $this->actingAs($this->owner)->get(route('app.transfers.index'))->assertOk()->assertSee($transfer->reference);
        $this->actingAs($this->owner)->post(route('app.transfers.send', $transfer))->assertRedirect();
        $this->actingAs($this->owner)->post(route('app.transfers.receive', $transfer), [
            'received' => [$transfer->items()->value('id') => 6],
        ])->assertRedirect();

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(6.0, $this->inventory()->getAvailableStock($product, null, $this->second->id));
    }

    public function test_transfers_need_their_own_permission(): void
    {
        $this->setUpBusiness();
        $this->stockedProduct(20);

        $role = Role::factory()->for($this->business)
            ->withPermissions([PermissionRegistry::INVENTORY_VIEW])
            ->create();

        $viewer = User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => $this->main->id,
        ]);

        // HTML callers are redirected back with an explanation; an API caller
        // gets the blunt 403. Same convention as every other gated module.
        $this->actingAs($viewer)->get(route('app.transfers.index'))->assertRedirect();
        $this->actingAs($viewer)->getJson(route('app.transfers.index'))->assertStatus(403);
    }

    public function test_another_businesss_transfer_is_not_reachable(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => StockTransfer::factory()->create(),
        );

        $this->actingAs($this->owner)
            ->get(route('app.transfers.show', $stranger))
            ->assertNotFound();
    }
}
