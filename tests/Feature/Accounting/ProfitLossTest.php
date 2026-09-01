<?php

namespace Tests\Feature\Accounting;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\ProfitService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
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
 * Profit & Loss (#45, #135, #183).
 *
 * The arithmetic this file exists to pin down:
 *
 *     Revenue − COGS = GROSS PROFIT
 *     GROSS PROFIT − Expenses + Other income = NET PROFIT
 *
 * And the four judgements inside it that a naive implementation gets wrong:
 *   1. TAX IS NOT REVENUE — the shop collects it, it does not earn it.
 *   2. COST IS THE SALE'S OWN SNAPSHOT (weighted average at the time), so a
 *      later delivery at a new price cannot rewrite a closed month.
 *   3. ONLY RESTOCKED RETURNS REVERSE COGS. A written-off return gives the
 *      money back and keeps the cost — because the goods are gone.
 *   4. OTHER INCOME SITS BELOW GROSS PROFIT, so it can never flatter margin.
 */
class ProfitLossTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'P&L Test Shop']);
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

        $this->actingAs($this->owner);
    }

    protected function profit(): ProfitService
    {
        return app(ProfitService::class);
    }

    /** @return array{from: string, to: string, days: int} */
    protected function thisMonth(): array
    {
        return [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'days' => now()->day,
        ];
    }

    protected function stocked(float $cost = 40, float $price = 100, float $quantity = 200, ?int $branchId = null): Product
    {
        $product = app(ProductService::class)->create([
            'name' => 'Cola '.fake()->unique()->numerify('###'),
            'type' => ProductType::Standard->value,
            'cost_price' => $cost,
            'selling_price' => $price,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $branchId ?? $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => $quantity,
            'unit_cost' => $cost,
        ]);

        return $product;
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function sell(array $lines, array $attributes = []): Sale
    {
        return app(SaleService::class)->complete(
            $attributes,
            $lines,
            [['method' => 'cash', 'amount' => 1000000]],
        );
    }

    protected function spend(float $amount, string $category = 'Rent', ?string $date = null): void
    {
        $categoryModel = ExpenseCategory::query()->where('name', $category)->first()
            ?? app(ExpenseService::class)->createCategory(['name' => $category]);

        app(ExpenseService::class)->create([
            'expense_category_id' => $categoryModel->id,
            'amount' => $amount,
            'expense_date' => $date ?? now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
    }

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions): User
    {
        $role = Role::factory()->for($this->business)->withPermissions($permissions)->create();

        return User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    // ================================================== the whole statement

    public function test_the_statement_adds_up_from_revenue_to_net_profit(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        // 10 sold at 100 = 1,000 revenue, 400 cost.
        $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        $this->spend(250, 'Rent');
        $this->spend(120, 'Utilities');

        app(ExpenseService::class)->createIncome([
            'amount' => 70,
            'source' => 'Scrap cartons',
            'payment_method' => 'bank_transfer',
        ]);

        $s = $this->profit()->statement();

        $this->assertSame(1000.0, $s['revenue']['net']);
        $this->assertSame(400.0, $s['cogs']['net']);
        $this->assertSame(600.0, $s['gross_profit'], '1,000 − 400.');
        $this->assertSame(60.0, $s['gross_margin']);

        $this->assertSame(370.0, $s['expenses']['total'], '250 + 120.');
        $this->assertSame(70.0, $s['other_income']['total']);

        $this->assertSame(300.0, $s['net_profit'], '600 − 370 + 70.');
        $this->assertSame(30.0, $s['net_margin']);

        // The statement names its own costing method, so nobody has to guess.
        $this->assertSame('Weighted average cost', $s['cost_method']);
    }

    public function test_tax_is_not_revenue(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        // 10 × 100 = 1,000, plus 10% tax = 1,100 taken at the counter.
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10, 'tax_rate' => 10]]);

        $this->assertSame(1100.0, (float) $sale->total);
        $this->assertSame(100.0, (float) $sale->tax_total);

        $s = $this->profit()->statement();

        $this->assertSame(1000.0, $s['revenue']['net'], 'The 100 of tax belongs to the government.');
        $this->assertSame(600.0, $s['gross_profit']);
        $this->assertSame(60.0, $s['gross_margin'], 'Counting the tax would have reported 66%.');
    }

    public function test_held_and_voided_sales_are_not_revenue(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 5]]);

        // A held sale has not happened…
        app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 5]]);

        // …and a voided one has been undone.
        $voided = $this->sell([['product_id' => $product->id, 'quantity' => 5]]);
        app(SaleService::class)->void($voided, 'Rung up twice');

        $s = $this->profit()->statement();

        $this->assertSame(500.0, $s['revenue']['net'], 'Only the one real sale.');
        $this->assertSame(1, $s['revenue']['sales_count']);
        $this->assertSame(200.0, $s['cogs']['net']);
    }

    // ================================================ cost is a snapshot

    public function test_cost_is_the_sale_s_own_snapshot_not_today_s_average(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100, quantity: 10);

        $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        $before = $this->profit()->statement();
        $this->assertSame(400.0, $before['cogs']['net']);

        // A later delivery at more than double the price re-weights the shelf.
        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 100,
            'unit_cost' => 90,
        ]);

        $after = $this->profit()->statement();

        $this->assertSame(400.0, $after['cogs']['net'], 'A closed month stays closed.');
        $this->assertSame($before['gross_profit'], $after['gross_profit']);
    }

    // ========================================== returns, and the two kinds

    public function test_a_restocked_return_reverses_both_revenue_and_cost(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        app(SaleReturnService::class)->create($sale, ['reason' => 'Unopened'], [
            $sale->items->first()->id => ['quantity' => 4, 'restock' => true],
        ]);

        $s = $this->profit()->statement();

        $this->assertSame(1000.0, $s['revenue']['gross']);
        $this->assertSame(400.0, $s['revenue']['returns'], '4 × 100 handed back.');
        $this->assertSame(600.0, $s['revenue']['net']);

        $this->assertSame(400.0, $s['cogs']['sold']);
        $this->assertSame(160.0, $s['cogs']['restocked'], '4 × 40 back on the shelf.');
        $this->assertSame(0.0, $s['cogs']['written_off']);
        $this->assertSame(240.0, $s['cogs']['net']);

        $this->assertSame(360.0, $s['gross_profit'], 'Exactly the profit on the 6 they kept.');
    }

    public function test_a_written_off_return_reverses_the_revenue_but_keeps_the_cost(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        // Smashed: the customer is paid, the goods are gone.
        app(SaleReturnService::class)->create($sale, ['reason' => 'Arrived broken'], [
            $sale->items->first()->id => ['quantity' => 4, 'restock' => false, 'condition_note' => 'Shattered'],
        ]);

        $s = $this->profit()->statement();

        $this->assertSame(600.0, $s['revenue']['net'], 'Revenue reverses in full.');

        $this->assertSame(0.0, $s['cogs']['restocked'], 'Nothing came back to the shelf.');
        $this->assertSame(160.0, $s['cogs']['written_off'], 'Paid for, and gone.');
        $this->assertSame(400.0, $s['cogs']['net'], 'The whole original cost stands.');

        // 600 revenue − 400 cost. The breakage cost the shop the full 400 of
        // sale value it refunded, not just the margin on it.
        $this->assertSame(200.0, $s['gross_profit']);

        // …and the return document says the same thing.
        $return = $sale->returns()->with('items')->firstOrFail();
        $this->assertSame(0.0, $return->restockedCost());
        $this->assertSame(160.0, $return->writtenOffCost());
        $this->assertSame(400.0, $return->profitReversed(), 'The whole sale value, not 400 − 160.');
    }

    public function test_a_return_lands_on_the_day_it_came_back(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 5]]);

        app(SaleReturnService::class)->create($sale, [
            'reason' => 'Later',
            'return_date' => now()->toDateString(),
        ], [$sale->items->first()->id => ['quantity' => 5, 'restock' => true]]);

        // A period that ends yesterday sees the sale but not the refund, because
        // the refund had not happened yet.
        $yesterday = $this->profit()->statement([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->subDay()->toDateString(),
        ]);

        if (now()->day > 1) {
            $this->assertSame(0.0, $yesterday['revenue']['returns'], 'A refund cannot reopen a closed day.');
        }

        $today = $this->profit()->statement();
        $this->assertSame(500.0, $today['revenue']['returns']);
        $this->assertSame(0.0, $today['revenue']['net']);
    }

    // ======================================= expenses and income in their place

    public function test_expenses_are_grouped_by_category_with_their_share(): void
    {
        $this->setUpBusiness();

        $this->spend(600, 'Rent');
        $this->spend(200, 'Utilities');
        $this->spend(200, 'Utilities');

        $s = $this->profit()->statement();

        $this->assertSame(1000.0, $s['expenses']['total']);
        $this->assertSame(3, $s['expenses']['count']);

        $rows = $s['expenses']['by_category'];

        // Biggest first, because that is the one worth doing something about.
        $this->assertSame('Rent', $rows[0]['name']);
        $this->assertSame(600.0, $rows[0]['amount']);
        $this->assertSame(60.0, $rows[0]['share']);

        $this->assertSame('Utilities', $rows[1]['name']);
        $this->assertSame(400.0, $rows[1]['amount']);
        $this->assertSame(2, $rows[1]['count']);
    }

    public function test_other_income_never_flatters_the_margin(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        $withoutIncome = $this->profit()->statement();

        app(ExpenseService::class)->createIncome([
            'amount' => 500,
            'source' => 'Insurance settlement',
            'payment_method' => 'bank_transfer',
        ]);

        $withIncome = $this->profit()->statement();

        // Gross profit and margin are untouched — no stock left the shelf.
        $this->assertSame($withoutIncome['gross_profit'], $withIncome['gross_profit']);
        $this->assertSame($withoutIncome['gross_margin'], $withIncome['gross_margin']);
        $this->assertSame($withoutIncome['revenue']['net'], $withIncome['revenue']['net']);

        // It only moves the bottom line.
        $this->assertSame($withoutIncome['net_profit'] + 500.0, $withIncome['net_profit']);
    }

    public function test_a_period_that_cost_more_than_it_earned_reports_a_loss(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);
        $this->spend(5000, 'Rent');

        $s = $this->profit()->statement();

        $this->assertSame(120.0, $s['gross_profit']);
        $this->assertSame(-4880.0, $s['net_profit'], 'A loss is a number, not an error.');
    }

    // ============================================================== branches

    public function test_branch_statements_add_up_to_the_business(): void
    {
        $this->setUpBusiness();

        $depot = app(BranchService::class)->create([
            'name' => 'Depot',
            'code' => 'DEP',
        ]);

        $main = $this->stocked(cost: 40, price: 100);
        $other = $this->stocked(cost: 20, price: 50, branchId: $depot->id);

        $this->sell([['product_id' => $main->id, 'quantity' => 10]], ['branch_id' => $this->branch->id]);
        $this->sell([['product_id' => $other->id, 'quantity' => 10]], ['branch_id' => $depot->id]);

        $this->spend(300, 'Rent');

        $whole = $this->profit()->statement();
        $mainOnly = $this->profit()->statement(['branch_id' => $this->branch->id]);
        $depotOnly = $this->profit()->statement(['branch_id' => $depot->id]);

        $this->assertSame(1500.0, $whole['revenue']['net'], '1,000 + 500.');
        $this->assertSame(1000.0, $mainOnly['revenue']['net']);
        $this->assertSame(500.0, $depotOnly['revenue']['net']);

        // The property that matters: nothing hides between the two.
        $this->assertSame(
            $whole['net_profit'],
            round($mainOnly['net_profit'] + $depotOnly['net_profit'], 2),
            'A branch-less expense would break this — which is why they cannot exist.',
        );
    }

    // ================================================================ daily

    public function test_the_daily_rows_sum_to_the_statement(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 7]]);
        $this->spend(150, 'Rent');

        $s = $this->profit()->statement();
        $daily = $this->profit()->daily(['from' => $s['from'], 'to' => $s['to'], 'days' => $s['days']]);

        $this->assertCount($s['days'], $daily, 'One row per day, including the quiet ones.');
        $this->assertSame($s['revenue']['net'], round($daily->sum('revenue'), 2));
        $this->assertSame($s['cogs']['net'], round($daily->sum('cogs'), 2));
        $this->assertSame($s['net_profit'], round($daily->sum('net_profit'), 2));
    }

    public function test_a_backwards_date_range_is_read_the_right_way_round(): void
    {
        $this->setUpBusiness();

        $s = $this->profit()->statement([
            'from' => now()->toDateString(),
            'to' => now()->subDays(6)->toDateString(),
        ]);

        $this->assertSame(now()->subDays(6)->toDateString(), $s['from']);
        $this->assertSame(now()->toDateString(), $s['to']);
        $this->assertSame(7, $s['days']);
    }

    // ================================================== through the interface

    public function test_the_screen_shows_the_statement(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 10]]);
        $this->spend(250, 'Rent');

        $this->actingAs($this->owner)
            ->get(route('app.reports.profit-loss'))
            ->assertOk()
            ->assertSee('Gross profit')
            ->assertSee('Net profit')
            ->assertSee('Weighted average cost')
            ->assertSee('1,000.00')   // revenue
            ->assertSee('600.00')     // gross profit
            ->assertSee('350.00');    // net profit
    }

    // ============================================== who is allowed to see it

    public function test_profit_is_a_permission_not_just_a_plan(): void
    {
        $this->setUpBusiness();

        // A manager who runs the shop day to day still may not see what it earns.
        $manager = $this->userWith([
            PermissionRegistry::SALES_VIEW,
            PermissionRegistry::EXPENSES_VIEW,
            PermissionRegistry::REPORTS_VIEW,
        ]);

        $this->actingAs($manager)
            ->get(route('app.reports.profit-loss'))
            ->assertRedirect()
            ->assertSessionHas('permission_denied');

        $accountant = $this->userWith([
            PermissionRegistry::REPORTS_VIEW,
            PermissionRegistry::REPORTS_VIEW_PROFIT,
        ]);

        $this->actingAs($accountant)->get(route('app.reports.profit-loss'))->assertOk();
    }

    public function test_a_plan_without_profit_and_loss_is_sent_to_billing(): void
    {
        $this->setUpBusiness([FeatureRegistry::ACCOUNTING_PROFIT_LOSS => false]);

        $this->actingAs($this->owner)
            ->get(route('app.reports.profit-loss'))
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('feature_unavailable');

        $this->expectException(FeatureUnavailableException::class);
        $this->profit()->statement();
    }

    public function test_another_shops_figures_are_not_in_this_statement(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 5]]);
        $this->spend(100, 'Rent');

        $mine = $this->profit()->statement();

        // A second shop, built with the tenant stamp out of the way.
        app(TenantContext::class)->forget();

        $other = Business::factory()->create(['name' => 'Somebody Else']);
        $otherOwner = User::factory()->for($other)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();
        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }
        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }
        Subscription::factory()->forBusiness($other)->forPlan($plan)->create();
        app(OrganizationProvisioner::class)->provision($other);

        app(TenantContext::class)->setBusiness($other);
        app(BranchContext::class)->forUser($otherOwner->fresh());
        $this->actingAs($otherOwner->fresh());

        $theirs = $this->profit()->statement();

        $this->assertSame(500.0, $mine['revenue']['net']);
        $this->assertSame(0.0, $theirs['revenue']['net'], 'A new shop has earned nothing.');
        $this->assertSame(0.0, $theirs['expenses']['total']);
        $this->assertSame(0.0, $theirs['net_profit']);
    }
}
