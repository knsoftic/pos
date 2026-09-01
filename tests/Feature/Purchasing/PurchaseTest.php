<?php

namespace Tests\Feature\Purchasing;

use App\Enums\LedgerEntryType;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BranchService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
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
 * Purchases and purchase returns (#35–#37, #119, #183).
 *
 * The decisions these tests exist to protect:
 *   1. ORDERING POSTS NOTHING. Stock and the supplier's account move on receipt,
 *      and only for what actually arrived.
 *   2. PARTIAL IS NORMAL. Receiving in instalments accumulates; a second receipt
 *      never double-counts the first.
 *   3. ONE TRANSACTION (#119): goods, ledger and payment land together or not
 *      at all.
 *   4. YOU CANNOT RETURN MORE THAN ARRIVED.
 */
class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Purchasing Shop']);
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
            LimitRegistry::SUPPLIERS => 50,
            LimitRegistry::CUSTOMERS => 50,
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
        $this->supplier = app(SupplierService::class)->create(['name' => 'Metro Wholesale']);

        $this->actingAs($this->owner);
    }

    protected function purchases(): PurchaseService
    {
        return app(PurchaseService::class);
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

    /** A draft with one line: 10 × 50. */
    protected function draft(?Product $product = null, array $lineOverrides = []): Purchase
    {
        $product ??= $this->product();

        return $this->purchases()->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'order_date' => now()->toDateString(),
        ], [
            array_merge([
                'product_id' => $product->id,
                'quantity_ordered' => 10,
                'unit_cost' => 50,
            ], $lineOverrides),
        ]);
    }

    // ============================================ drafting & ordering

    public function test_a_draft_posts_nothing_at_all(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->draft($product);

        $this->assertSame(PurchaseStatus::Draft, $purchase->status);
        $this->assertSame('PO-000001', $purchase->reference);
        $this->assertSame(500.0, (float) $purchase->total);

        // Nothing on the shelf, nothing on the account.
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
        $this->assertSame(0, StockMovement::query()->allBranches()->count());
    }

    public function test_ordering_still_posts_nothing(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));

        $this->assertSame(PurchaseStatus::Ordered, $purchase->status);
        $this->assertNotNull($purchase->ordered_at);

        // A purchase order is a request. The shop owns nothing new and owes
        // nothing new — this is the central decision of the whole phase.
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
    }

    public function test_line_arithmetic_is_defined_in_one_place(): void
    {
        $this->setUpBusiness();

        // 10 × 50 = 500, less 50 discount = 450, plus 10% tax = 495.
        $purchase = $this->draft(null, [
            'unit_cost' => 50,
            'discount_amount' => 50,
            'tax_rate' => 10,
        ]);

        $item = $purchase->items->first();

        $this->assertSame(500.0, $item->gross());
        $this->assertSame(45.0, $item->taxAmount());
        $this->assertSame(495.0, $item->net());
        $this->assertSame(49.5, $item->effectiveUnitCost(), 'The discount spreads across the ordered quantity.');

        $this->assertSame(500.0, (float) $purchase->subtotal);
        $this->assertSame(50.0, (float) $purchase->discount_total);
        $this->assertSame(45.0, (float) $purchase->tax_total);
        $this->assertSame(495.0, (float) $purchase->total);
    }

    public function test_only_a_draft_can_be_edited(): void
    {
        $this->setUpBusiness();
        $purchase = $this->purchases()->order($this->draft());

        $this->expectException(HttpException::class);

        $this->purchases()->update($purchase, ['supplier_id' => $this->supplier->id], [
            ['product_id' => $purchase->items->first()->product_id, 'quantity_ordered' => 99, 'unit_cost' => 1],
        ]);
    }

    public function test_a_blocked_supplier_cannot_be_ordered_from(): void
    {
        $this->setUpBusiness();
        $purchase = $this->draft();

        app(SupplierService::class)->setActive($this->supplier, false, 'Quality dispute');

        $this->expectException(HttpException::class);

        $this->purchases()->order($purchase->fresh());
    }

    // ================================================= receiving (#119)

    public function test_receiving_posts_stock_and_debits_the_supplier_together(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $purchase = $this->purchases()->receive($purchase);

        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertNotNull($purchase->completed_at);

        // Stock arrived, valued at what this delivery cost.
        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product));

        $movement = StockMovement::query()->allBranches()->firstOrFail();
        $this->assertSame(StockMovementType::Purchase, $movement->type);
        $this->assertSame(50.0, (float) $movement->unit_cost, 'Not the catalogue cost of 40 — what was actually paid.');
        $this->assertSame($purchase->getMorphClass(), $movement->reference_type);

        // And the supplier is owed for it.
        $this->assertSame(500.0, $this->supplier->fresh()->weOwe());

        $entry = LedgerEntry::query()->forParty($this->supplier)->latest('id')->firstOrFail();
        $this->assertSame(LedgerEntryType::Purchase, $entry->type);
        $this->assertSame(500.0, (float) $entry->debit);
    }

    public function test_a_short_delivery_becomes_partial_and_only_bills_what_arrived(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $item = $purchase->items->first();

        // Only 4 of the 10 turned up.
        $purchase = $this->purchases()->receive($purchase, [$item->id => 4]);

        $this->assertSame(PurchaseStatus::Partial, $purchase->status);
        $this->assertSame(6.0, $purchase->outstandingQuantity());
        $this->assertSame(4.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(200.0, $this->supplier->fresh()->weOwe(), 'You owe for goods you have.');
        $this->assertSame(500.0, (float) $purchase->total, 'The order itself still says what was ordered.');
    }

    public function test_a_second_receipt_accumulates_without_double_counting(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $item = $purchase->items->first();

        $this->purchases()->receive($purchase, [$item->id => 4]);
        $purchase = $this->purchases()->receive($purchase->fresh(), [$item->id => 6]);

        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertSame(10.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(500.0, $this->supplier->fresh()->weOwe(), 'Not 700 — the first four are not billed twice.');
        $this->assertSame(2, StockMovement::query()->allBranches()->count());
    }

    public function test_receiving_more_than_was_ordered_is_refused(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $item = $purchase->items->first();

        try {
            $this->purchases()->receive($purchase, [$item->id => 12]);
            $this->fail('An over-delivery was silently accepted.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('only 10 can be taken in', $e->getMessage());
        }

        // And nothing was half-applied.
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
        $this->assertSame(PurchaseStatus::Ordered, $purchase->fresh()->status);
    }

    public function test_paying_at_the_door_settles_in_the_same_transaction(): void
    {
        $this->setUpBusiness();

        $purchase = $this->purchases()->order($this->draft());
        $purchase = $this->purchases()->receive($purchase, [], [
            'pay_now' => 500,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(500.0, (float) $purchase->paid_amount);
        $this->assertSame(0.0, $purchase->balanceDue());
        $this->assertTrue($purchase->isSettled());

        // The account nets to nothing: billed 500, paid 500.
        $this->assertTrue($this->supplier->fresh()->isSettled());
        $this->assertSame(2, LedgerEntry::query()->forParty($this->supplier)->count());
    }

    public function test_a_bill_can_be_settled_later(): void
    {
        $this->setUpBusiness();

        $purchase = $this->purchases()->receive($this->purchases()->order($this->draft()));

        $this->assertSame(500.0, $purchase->balanceDue());

        $purchase = $this->purchases()->settle($purchase, 200, ['payment_method' => 'bank_transfer']);

        $this->assertSame(200.0, (float) $purchase->paid_amount);
        $this->assertSame(300.0, $purchase->balanceDue());
        $this->assertSame(300.0, $this->supplier->fresh()->weOwe());
    }

    public function test_nothing_can_be_paid_before_anything_arrives(): void
    {
        $this->setUpBusiness();
        $purchase = $this->purchases()->order($this->draft());

        $this->expectException(HttpException::class);

        $this->purchases()->settle($purchase, 100);
    }

    public function test_a_service_line_is_billed_but_never_shelved(): void
    {
        $this->setUpBusiness();

        $carriage = $this->product(['name' => 'Carriage', 'type' => ProductType::Service->value]);

        $purchase = $this->purchases()->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
        ], [
            ['product_id' => $carriage->id, 'quantity_ordered' => 1, 'unit_cost' => 750],
        ]);

        $purchase = $this->purchases()->receive($this->purchases()->order($purchase));

        $this->assertSame(750.0, $this->supplier->fresh()->weOwe());
        $this->assertSame(0, StockMovement::query()->allBranches()->count(),
            'Carriage is a cost, not a thing on a shelf.');
    }

    public function test_a_batch_tracked_line_carries_its_lot_and_expiry(): void
    {
        $this->setUpBusiness();

        $milk = $this->product([
            'name' => 'Fresh Milk 1L',
            'cost_price' => 180,
            'selling_price' => 240,
            'tracks_batches' => true,
        ]);

        $purchase = $this->purchases()->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
        ], [
            [
                'product_id' => $milk->id,
                'quantity_ordered' => 24,
                'unit_cost' => 180,
                'batch_number' => 'MLK-9911',
                'expiry_date' => now()->addDays(10)->toDateString(),
            ],
        ]);

        $this->purchases()->receive($this->purchases()->order($purchase));

        $batch = StockBatch::query()->allBranches()->firstOrFail();

        $this->assertSame('MLK-9911', $batch->batch_number, 'The delivery note is where a lot number comes from.');
        $this->assertSame(24.0, (float) $batch->quantity);
        $this->assertTrue($batch->isExpiringSoon());
    }

    // ==================================================== calling off

    public function test_cancelling_a_draft_costs_nothing(): void
    {
        $this->setUpBusiness();
        $purchase = $this->purchases()->cancel($this->draft(), 'Ordered by mistake');

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->status);
        $this->assertSame('Ordered by mistake', $purchase->cancellation_reason);
        $this->assertSame(0.0, (float) $this->supplier->fresh()->balance);
    }

    public function test_cancelling_a_partial_keeps_what_already_arrived(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $item = $purchase->items->first();
        $purchase = $this->purchases()->receive($purchase, [$item->id => 4]);

        $purchase = $this->purchases()->cancel($purchase, 'The rest is never coming');

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->status);

        // What arrived is still on the shelf and still owed for. Only the
        // outstanding remainder is abandoned.
        $this->assertSame(4.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(200.0, $this->supplier->fresh()->weOwe());
    }

    public function test_only_an_untouched_draft_can_be_deleted(): void
    {
        $this->setUpBusiness();

        $draft = $this->draft();
        $this->assertTrue($this->purchases()->delete($draft));

        $posted = $this->purchases()->receive($this->purchases()->order($this->draft()));
        $this->assertFalse($this->purchases()->delete($posted), 'A financial record is cancelled, never deleted.');
    }

    // ================================================== returns (#37)

    public function test_a_return_takes_stock_off_and_credits_the_supplier(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->receive($this->purchases()->order($this->draft($product)));
        $item = $purchase->items->first();

        $return = app(PurchaseReturnService::class)->create($purchase, [
            'reason' => 'Three cases arrived damaged',
        ], [$item->id => 3]);

        $this->assertSame('PR-000001', $return->reference);
        $this->assertSame(150.0, (float) $return->total);

        $this->assertSame(7.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(350.0, $this->supplier->fresh()->weOwe(), '500 billed less 150 returned.');

        $entry = LedgerEntry::query()->forParty($this->supplier)->latest('id')->firstOrFail();
        $this->assertSame(LedgerEntryType::PurchaseReturn, $entry->type);
        $this->assertSame(150.0, (float) $entry->credit);
    }

    public function test_more_cannot_go_back_than_arrived(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->order($this->draft($product));
        $item = $purchase->items->first();
        $purchase = $this->purchases()->receive($purchase, [$item->id => 4]);

        try {
            app(PurchaseReturnService::class)->create($purchase, ['reason' => 'All of it'], [$item->id => 6]);
            $this->fail('More was returned than ever arrived.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('only 4 can be returned', $e->getMessage());
        }

        $this->assertSame(4.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_returns_accumulate_against_the_same_line(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $purchase = $this->purchases()->receive($this->purchases()->order($this->draft($product)));
        $item = $purchase->items->first();

        app(PurchaseReturnService::class)->create($purchase, ['reason' => 'First batch damaged'], [$item->id => 3]);

        $this->assertSame(3.0, $item->fresh()->returnedQuantity());
        $this->assertSame(7.0, $item->fresh()->returnableQuantity());

        app(PurchaseReturnService::class)->create($purchase->fresh(), ['reason' => 'More damage found'], [$item->id => 7]);

        $this->assertSame(0.0, $item->fresh()->returnableQuantity());
        $this->assertSame(0.0, $this->inventory()->getAvailableStock($product));
        $this->assertTrue($this->supplier->fresh()->isSettled(), 'Everything billed has been credited back.');
    }

    public function test_nothing_can_be_returned_from_an_order_that_never_arrived(): void
    {
        $this->setUpBusiness();
        $purchase = $this->purchases()->order($this->draft());
        $item = $purchase->items->first();

        $this->expectException(HttpException::class);

        app(PurchaseReturnService::class)->create($purchase, ['reason' => 'Nope'], [$item->id => 1]);
    }

    public function test_a_return_needs_a_reason(): void
    {
        $this->setUpBusiness();
        $purchase = $this->purchases()->receive($this->purchases()->order($this->draft()));
        $item = $purchase->items->first();

        $this->expectException(HttpException::class);

        app(PurchaseReturnService::class)->create($purchase, ['reason' => ''], [$item->id => 1]);
    }

    // ================================================= gates & tenancy

    public function test_purchases_need_the_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::PURCHASES_ORDERS => false]);

        $this->expectException(FeatureUnavailableException::class);

        $this->draft();
    }

    public function test_returns_need_their_own_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::PURCHASES_RETURNS => false]);

        $purchase = $this->purchases()->receive($this->purchases()->order($this->draft()));

        $this->expectException(FeatureUnavailableException::class);

        app(PurchaseReturnService::class)->create($purchase, ['reason' => 'Damaged'], [
            $purchase->items->first()->id => 1,
        ]);
    }

    public function test_a_purchase_cannot_be_delivered_to_an_unreachable_branch(): void
    {
        $this->setUpBusiness();

        $second = app(BranchService::class)->create(['name' => 'Retail Park']);

        $cashier = User::factory()->for($this->business)->create(['branch_id' => $this->branch->id]);
        app(BranchContext::class)->forUser($cashier);

        $this->expectException(HttpException::class);

        $this->purchases()->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $second->id,
        ], [
            ['product_id' => $this->product()->id, 'quantity_ordered' => 1, 'unit_cost' => 10],
        ]);
    }

    public function test_another_businesss_supplier_cannot_be_purchased_from(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Supplier::factory()->create(),
        );

        $this->expectException(HttpException::class);

        $this->purchases()->create([
            'supplier_id' => $stranger->id,
            'branch_id' => $this->branch->id,
        ], [
            ['product_id' => $this->product()->id, 'quantity_ordered' => 1, 'unit_cost' => 10],
        ]);
    }
}
