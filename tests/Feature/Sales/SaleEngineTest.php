<?php

namespace Tests\Feature\Sales;

use App\Enums\LedgerEntryType;
use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Support\BranchContext;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The sale engine (#17–#22, #40, #46, #70, #118, #139, #184).
 *
 * What these tests exist to protect:
 *   1. ALL SIXTEEN STEPS OR NONE. A refused sale leaves no stock moved, no money
 *      recorded and no invoice number spent.
 *   2. THE LOCK IS THE RACE PROTECTION (#70). Two tills cannot both sell the
 *      last unit.
 *   3. COST IS SNAPSHOTTED FROM THE SHELF (#52), so last month's margin does not
 *      change when this month's delivery arrives at a different price.
 *   4. SPLIT PAYMENTS MUST ADD UP (#19), and anything short needs a customer.
 */
class SaleEngineTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Till Test Shop']);
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

        // Provisioning parked the owner in the main branch; the instance held
        // here predates that.
        $this->owner->refresh();

        $this->actingAs($this->owner);
    }

    protected function sales(): SaleService
    {
        return app(SaleService::class);
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    /** A product with stock on the shelf, bought in at a known cost. */
    protected function stockedProduct(float $quantity = 50, float $cost = 40, float $price = 70, array $overrides = []): Product
    {
        $product = app(ProductService::class)->create(array_merge([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => $cost,
            'selling_price' => $price,
        ], $overrides));

        if ($quantity > 0) {
            $this->inventory()->createMovement([
                'product' => $product,
                'branch_id' => $this->branch->id,
                'type' => StockMovementType::Purchase,
                'quantity' => $quantity,
                'unit_cost' => $cost,
            ]);
        }

        return $product;
    }

    protected function line(Product $product, float $quantity = 1, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ], $overrides);
    }

    // ================================================ the sixteen steps

    public function test_a_cash_sale_moves_stock_money_and_nothing_else(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(quantity: 50, cost: 40, price: 70);

        $sale = $this->sales()->complete([], [$this->line($product, 3)], [
            ['method' => 'cash', 'amount' => 210],
        ]);

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(210.0, (float) $sale->total);
        $this->assertSame(210.0, (float) $sale->paid_total);
        $this->assertSame(0.0, (float) $sale->due_amount);
        $this->assertTrue($sale->isFullyPaid());

        // Stock came off the shelf.
        $this->assertSame(47.0, $this->inventory()->getAvailableStock($product));

        $movement = StockMovement::query()->allBranches()->where('type', StockMovementType::Sale)->firstOrFail();
        $this->assertSame(-3.0, (float) $movement->quantity);
        $this->assertSame($sale->getMorphClass(), $movement->reference_type);

        // A walk-in owes nothing, so no ledger entry exists at all.
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_the_invoice_number_follows_the_configured_format(): void
    {
        $this->setUpBusiness();
        config(['pos.invoice.format' => '{PREFIX}-{YYYY}-{SEQ:4}', 'pos.invoice.prefix' => 'SI']);

        $product = $this->stockedProduct();

        $first = $this->sales()->complete([], [$this->line($product)], [['method' => 'cash', 'amount' => 70]]);
        $second = $this->sales()->complete([], [$this->line($product)], [['method' => 'cash', 'amount' => 70]]);

        $this->assertSame('SI-'.now()->format('Y').'-0001', $first->invoice_no);
        $this->assertSame('SI-'.now()->format('Y').'-0002', $second->invoice_no);
    }

    public function test_the_cost_is_snapshotted_from_the_shelf_not_the_catalogue(): void
    {
        $this->setUpBusiness();

        // Catalogue says 40, but the shelf was actually bought in at 55.
        $product = $this->stockedProduct(quantity: 0, cost: 40, price: 100);

        $this->inventory()->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 10,
            'unit_cost' => 55,
        ]);

        $sale = $this->sales()->complete([], [$this->line($product, 2)], [['method' => 'cash', 'amount' => 200]]);

        $this->assertSame(55.0, (float) $sale->items->first()->unit_cost);
        $this->assertSame(110.0, (float) $sale->cost_total);
        $this->assertSame(90.0, $sale->grossProfit(), '200 sold, 110 cost.');
    }

    public function test_line_arithmetic_is_defined_in_one_place(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        // 4 × 100 = 400, less 50 discount = 350, plus 10% tax = 385.
        $sale = $this->sales()->complete([], [
            $this->line($product, 4, ['discount_amount' => 50, 'tax_rate' => 10]),
        ], [['method' => 'cash', 'amount' => 385]]);

        $this->assertSame(400.0, (float) $sale->subtotal);
        $this->assertSame(50.0, (float) $sale->discount_total);
        $this->assertSame(35.0, (float) $sale->tax_total);
        $this->assertSame(385.0, (float) $sale->total);
    }

    // ------------------------------------------------------- payments (#19)

    public function test_a_split_payment_is_recorded_method_by_method(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $sale = $this->sales()->complete([], [$this->line($product, 5)], [
            ['method' => 'card', 'amount' => 300, 'reference' => 'AUTH-9931'],
            ['method' => 'cash', 'amount' => 200],
        ]);

        $this->assertSame(500.0, (float) $sale->paid_total);
        $this->assertSame(2, $sale->payments->count(), 'Each method is reconcilable on its own.');
        $this->assertSame('AUTH-9931', $sale->payments->firstWhere('method', 'card')->reference);
        $this->assertSame(200.0, $sale->cashTaken(), 'Only the cash half touches the drawer.');
        $this->assertSame('Card + Cash', $sale->methodSummary());
    }

    public function test_change_is_given_and_only_what_was_applied_is_recorded(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 85);

        // 1,000 handed over for an 850 sale.
        $sale = $this->sales()->complete([], [$this->line($product, 10)], [
            ['method' => 'cash', 'amount' => 1000],
        ]);

        $this->assertSame(850.0, (float) $sale->total);
        $this->assertSame(850.0, (float) $sale->paid_total);
        $this->assertSame(150.0, (float) $sale->change_given);
        $this->assertSame(850.0, (float) $sale->payments->first()->amount,
            'The payment is what was applied, not what was handed over.');
        $this->assertSame(850.0, $sale->cashTaken(), 'The drawer nets 850, which is what it actually gained.');
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct();

        $this->expectException(HttpException::class);

        $this->sales()->complete([], [$this->line($product)], [
            ['method' => 'crypto', 'amount' => 70],
        ]);
    }

    // ------------------------------------------------------- credit (#40)

    public function test_an_unpaid_remainder_goes_on_the_customers_account(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $customer = app(CustomerService::class)->create([
            'name' => 'Ayesha Traders',
            'credit_limit' => 10000,
        ]);

        // 500 sale, only 200 handed over.
        $sale = $this->sales()->complete(['customer_id' => $customer->id], [$this->line($product, 5)], [
            ['method' => 'cash', 'amount' => 200],
        ]);

        $this->assertSame(200.0, (float) $sale->paid_total);
        $this->assertSame(300.0, (float) $sale->due_amount);
        $this->assertFalse($sale->isFullyPaid());

        $this->assertSame(300.0, $customer->fresh()->owesUs());

        $entry = LedgerEntry::query()->forParty($customer)->latest('id')->firstOrFail();
        $this->assertSame(LedgerEntryType::Sale, $entry->type);
        $this->assertSame(300.0, (float) $entry->debit);
    }

    public function test_a_walk_in_cannot_owe_money(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        try {
            $this->sales()->complete([], [$this->line($product, 5)], [['method' => 'cash', 'amount' => 200]]);
            $this->fail('A walk-in was allowed to leave owing money.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('nobody to bill', $e->getMessage());
        }

        // Nothing was posted — the whole sale was refused.
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(50.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_a_sale_past_the_credit_limit_is_refused_whole(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $customer = app(CustomerService::class)->create(['name' => 'Small Account', 'credit_limit' => 200]);

        try {
            $this->sales()->complete(['customer_id' => $customer->id], [$this->line($product, 5)], []);
            $this->fail('A sale went past the credit limit.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }

        // Step 14 failing rolls back steps 9–13 as well: no sale, no stock
        // movement, no invoice number spent.
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(50.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(0, StockMovement::query()->allBranches()->where('type', StockMovementType::Sale)->count());
        $this->assertSame(0.0, (float) $customer->fresh()->balance);
    }

    // -------------------------------------------------- stock & races (#70)

    public function test_selling_more_than_is_on_the_shelf_is_refused_and_nothing_is_written(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(quantity: 3, price: 70);

        try {
            $this->sales()->complete([], [$this->line($product, 5)], [['method' => 'cash', 'amount' => 350]]);
            $this->fail('More was sold than existed.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(3.0, $e->available);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(3.0, $this->inventory()->getAvailableStock($product));
    }

    public function test_the_last_unit_can_only_be_sold_once(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(quantity: 1, price: 70);

        $this->sales()->complete([], [$this->line($product, 1)], [['method' => 'cash', 'amount' => 70]]);

        // The second till arrives to find the shelf empty. The lock inside
        // InventoryService is what makes this deterministic (#70).
        $this->expectException(InsufficientStockException::class);

        $this->sales()->complete([], [$this->line($product, 1)], [['method' => 'cash', 'amount' => 70]]);
    }

    public function test_a_service_line_sells_without_touching_stock(): void
    {
        $this->setUpBusiness();

        $haircut = app(ProductService::class)->create([
            'name' => 'Delivery',
            'type' => ProductType::Service->value,
            'selling_price' => 250,
        ]);

        $sale = $this->sales()->complete([], [$this->line($haircut, 1)], [['method' => 'cash', 'amount' => 250]]);

        $this->assertSame(250.0, (float) $sale->total);
        $this->assertSame(0, StockMovement::query()->allBranches()->count());
    }

    public function test_a_batch_tracked_product_leaves_by_earliest_expiry(): void
    {
        $this->setUpBusiness();

        $milk = app(ProductService::class)->create([
            'name' => 'Fresh Milk 1L',
            'type' => ProductType::Standard->value,
            'cost_price' => 180,
            'selling_price' => 240,
            'tracks_batches' => true,
        ]);

        // Long-dated first, short-dated second — FEFO must take the short one.
        foreach ([['LONG', 30, 10], ['SHORT', 3, 10]] as [$lot, $days, $qty]) {
            $this->inventory()->createMovement([
                'product' => $milk,
                'branch_id' => $this->branch->id,
                'type' => StockMovementType::Purchase,
                'quantity' => $qty,
                'unit_cost' => 180,
                'batch_number' => $lot,
                'expiry_date' => now()->addDays($days)->toDateString(),
            ]);
        }

        $this->sales()->complete([], [$this->line($milk, 6)], [['method' => 'cash', 'amount' => 1440]]);

        $short = StockBatch::query()->allBranches()->where('batch_number', 'SHORT')->firstOrFail();
        $long = StockBatch::query()->allBranches()->where('batch_number', 'LONG')->firstOrFail();

        $this->assertSame(4.0, (float) $short->quantity);
        $this->assertSame(10.0, (float) $long->quantity);
    }

    // ------------------------------------------------- held sales (#20)

    public function test_a_held_sale_posts_nothing(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct();

        $held = $this->sales()->hold([], [$this->line($product, 2)]);

        $this->assertSame(SaleStatus::Held, $held->status);
        $this->assertStringStartsWith('HOLD-', $held->invoice_no, 'A held sale must not spend an invoice number.');
        $this->assertSame(50.0, $this->inventory()->getAvailableStock($product), 'Nothing has left the shelf.');
        $this->assertSame(0, StockMovement::query()->allBranches()->where('type', StockMovementType::Sale)->count());
    }

    public function test_resuming_a_held_sale_completes_it_and_clears_the_hold(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 70);

        $held = $this->sales()->hold([], [$this->line($product, 2)]);

        $completed = $this->sales()->resume($held, [['method' => 'cash', 'amount' => 140]]);

        $this->assertSame(SaleStatus::Completed, $completed->status);
        $this->assertStringStartsNotWith('HOLD-', $completed->invoice_no);
        $this->assertSame(48.0, $this->inventory()->getAvailableStock($product));
        $this->assertNull(Sale::query()->find($held->id), 'The hold posted nothing, so nothing is kept.');
    }

    public function test_a_held_sale_can_be_discarded_but_a_completed_one_cannot(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct();

        $held = $this->sales()->hold([], [$this->line($product)]);
        $this->assertTrue($this->sales()->discardHold($held));

        $sold = $this->sales()->complete([], [$this->line($product)], [['method' => 'cash', 'amount' => 70]]);
        $this->assertFalse($this->sales()->discardHold($sold), 'A sale is voided, never deleted.');
    }

    // ---------------------------------------------------- voiding (#198)

    public function test_voiding_reverses_the_postings_and_keeps_the_record(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(quantity: 50, price: 100);

        $customer = app(CustomerService::class)->create(['name' => 'Account Customer', 'credit_limit' => 10000]);

        $sale = $this->sales()->complete(['customer_id' => $customer->id], [$this->line($product, 4)], [
            ['method' => 'cash', 'amount' => 100],
        ]);

        $this->assertSame(46.0, $this->inventory()->getAvailableStock($product));
        $this->assertSame(300.0, $customer->fresh()->owesUs());

        $voided = $this->sales()->void($sale, 'Customer changed their mind');

        $this->assertSame(SaleStatus::Voided, $voided->status);
        $this->assertSame('Customer changed their mind', $voided->void_reason);

        // Stock is back, the account is cleared, and the invoice still exists.
        $this->assertSame(50.0, $this->inventory()->getAvailableStock($product));
        $this->assertTrue($customer->fresh()->isSettled());
        $this->assertNotNull(Sale::query()->find($sale->id), 'The record stays — somebody has the paper copy.');
    }

    public function test_a_void_needs_a_reason_and_only_works_once(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct();

        $sale = $this->sales()->complete([], [$this->line($product)], [['method' => 'cash', 'amount' => 70]]);

        try {
            $this->sales()->void($sale, '');
            $this->fail('A sale was voided with no reason.');
        } catch (HttpException) {
            // expected
        }

        $this->sales()->void($sale->fresh(), 'Rung up twice');

        $this->expectException(HttpException::class);
        $this->sales()->void($sale->fresh(), 'Again');
    }

    // ------------------------------------------- the cash drawer (#46, #139)

    public function test_cash_sales_land_in_the_open_session(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $session = app(CashSessionService::class)->open(['opening_float' => 2000]);

        $this->sales()->complete([], [$this->line($product, 3)], [['method' => 'cash', 'amount' => 300]]);
        $this->sales()->complete([], [$this->line($product, 2)], [['method' => 'card', 'amount' => 200]]);

        $session->refresh();

        $this->assertSame(300.0, (float) $session->cash_sales, 'Only the cash sale touches the drawer.');
        $this->assertSame(2300.0, $session->expectedCash());
    }

    public function test_closing_a_till_records_the_difference(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $session = app(CashSessionService::class)->open(['opening_float' => 1000]);
        $this->sales()->complete([], [$this->line($product, 5)], [['method' => 'cash', 'amount' => 500]]);

        // 1,500 expected; 1,450 actually counted.
        $closed = app(CashSessionService::class)->close($session->fresh(), 1450, 'Fifty short');

        $this->assertFalse($closed->isOpen());
        $this->assertSame(1500.0, (float) $closed->expected_cash);
        $this->assertSame(-50.0, (float) $closed->difference);
        $this->assertSame('50.00 short', $closed->differenceLabel());
        $this->assertFalse($closed->isBalanced());
    }

    public function test_only_one_session_can_be_open_on_a_till(): void
    {
        $this->setUpBusiness();

        app(CashSessionService::class)->open(['opening_float' => 1000]);

        $this->expectException(HttpException::class);

        app(CashSessionService::class)->open(['opening_float' => 500]);
    }

    public function test_paying_in_and_out_moves_the_expected_figure(): void
    {
        $this->setUpBusiness();

        $session = app(CashSessionService::class)->open(['opening_float' => 1000]);

        app(CashSessionService::class)->recordMovement($session, 500, 'Float top-up', true);
        app(CashSessionService::class)->recordMovement($session->fresh(), 200, 'Window cleaner', false);

        $this->assertSame(1300.0, $session->fresh()->expectedCash());
    }

    public function test_the_session_figure_can_be_rebuilt_from_the_payments(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct(price: 100);

        $session = app(CashSessionService::class)->open(['opening_float' => 1000]);
        $this->sales()->complete([], [$this->line($product, 3)], [['method' => 'cash', 'amount' => 300]]);

        // Corrupt the cache behind the service's back.
        CashSession::query()->whereKey($session->id)->update(['cash_sales' => 9999]);

        $result = app(CashSessionService::class)->recalculate($session->fresh());

        $this->assertTrue($result['drifted']);
        $this->assertSame(300.0, $result['after']);
    }

    public function test_a_shop_can_insist_on_an_open_till(): void
    {
        $this->setUpBusiness();
        config(['pos.require_cash_session' => true]);

        $product = $this->stockedProduct();

        $this->expectException(HttpException::class);

        $this->sales()->complete([], [$this->line($product)], [['method' => 'cash', 'amount' => 70]]);
    }

    // ----------------------------------------------------------- rounding

    public function test_cash_rounding_is_applied_and_shown_separately(): void
    {
        $this->setUpBusiness();
        config(['pos.cash_rounding' => 1]);

        $product = $this->stockedProduct(price: 33.33);

        // 3 × 33.33 = 99.99, rounded to 100.
        $sale = $this->sales()->complete([], [$this->line($product, 3)], [['method' => 'cash', 'amount' => 100]]);

        $this->assertSame(99.99, (float) $sale->subtotal);
        $this->assertSame(100.0, (float) $sale->total);
        $this->assertSame(0.01, (float) $sale->rounding, 'Shown, not buried inside a total that fails to add up.');
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_businesss_product_cannot_be_sold(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Product::factory()->create(),
        );

        $this->expectException(HttpException::class);

        $this->sales()->complete([], [['product_id' => $stranger->id, 'quantity' => 1]], [
            ['method' => 'cash', 'amount' => 10],
        ]);
    }

    public function test_another_businesss_customer_cannot_be_billed(): void
    {
        $this->setUpBusiness();
        $product = $this->stockedProduct();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Customer::factory()->create(),
        );

        $this->expectException(HttpException::class);

        $this->sales()->complete(['customer_id' => $stranger->id], [$this->line($product)], [
            ['method' => 'cash', 'amount' => 70],
        ]);
    }
}
