<?php

namespace Tests\Feature\Reporting;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\ProfitService;
use App\Services\ReportService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\ReportRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The reports module (#54, #55, #56, #134, #183).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. ACCURACY (#134). A held sale has not happened, a voided one has been
 *     undone, and a return reduces what was sold. A report that got any of
 *     those wrong would be acted on, which is worse than having no report.
 *  2. ONE DEFINITION OF REVENUE. The reports and the P&L must agree to the
 *     penny, or the owner has two numbers and no way to choose between them.
 *  3. THE REGISTRY IS COMPLETE. Every declared report actually builds — a
 *     catalogue entry with no query behind it is a 500 waiting for a customer.
 *  4. THE GATES ARE PER REPORT (#187), not per route.
 */
class ReportTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Reporting Test Shop']);
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

    protected function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /** @return array<string, string> */
    protected function period(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];
    }

    protected function stocked(string $name = 'Cola', float $cost = 40, float $price = 100, float $quantity = 200, ?int $branchId = null): Product
    {
        $product = app(ProductService::class)->create([
            'name' => $name,
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
    protected function sell(array $lines, array $attributes = [], ?array $payments = null): Sale
    {
        return app(SaleService::class)->complete(
            $attributes,
            $lines,
            $payments ?? [['method' => 'cash', 'amount' => 1000000]],
        );
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

    /** @return array<string, mixed> */
    protected function rowFor(array $report, string $key, string $value): array
    {
        $row = $report['rows']->firstWhere($key, $value);

        $this->assertNotNull($row, "No row where {$key} = {$value}.");

        return $row;
    }

    // ================================================ the registry is complete

    public function test_every_declared_report_actually_builds(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $customer = app(CustomerService::class)->create(['name' => 'Ledger Customer', 'credit_limit' => 10000]);

        $this->sell([['product_id' => $product->id, 'quantity' => 3]], ['customer_id' => $customer->id]);

        $filters = $this->period() + ['customer_id' => $customer->id];

        foreach (array_keys(ReportRegistry::all()) as $key) {
            $report = $this->reports()->build($key, $filters);

            $this->assertSame($key, $report['key']);
            $this->assertNotEmpty($report['columns'], "{$key} has no columns.");

            // Every column a builder emits must be one the report declared, or
            // the table would silently drop it.
            foreach ($report['columns'] as $column) {
                $this->assertArrayHasKey('key', $column, "{$key} has a column with no key.");
                $this->assertArrayHasKey('label', $column, "{$key} has a column with no label.");
            }
        }
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->setUpBusiness();

        $this->expectException(NotFoundHttpException::class);

        $this->reports()->build('sales.imaginary');
    }

    // =========================================== accuracy above all else (#134)

    public function test_held_and_voided_sales_are_not_in_any_takings_figure(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 5]]);

        app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 5]]);

        $voided = $this->sell([['product_id' => $product->id, 'quantity' => 5]]);
        app(SaleService::class)->void($voided, 'Rung up twice');

        $summary = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());
        $byProduct = $this->reports()->build(ReportRegistry::SALES_BY_PRODUCT, $this->period());

        $this->assertSame(1.0, (float) $summary['totals']['orders'], 'One sale actually happened.');
        $this->assertSame(500.0, (float) $summary['totals']['net']);
        $this->assertSame(500.0, (float) $byProduct['totals']['revenue']);
        $this->assertSame(5.0, (float) $byProduct['totals']['qty']);
    }

    public function test_returns_are_subtracted_and_shown_rather_than_hidden(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        app(SaleReturnService::class)->create($sale, ['reason' => 'Two back'], [
            $sale->items->first()->id => ['quantity' => 2, 'restock' => true],
        ]);

        $summary = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());

        $this->assertSame(1000.0, (float) $summary['totals']['gross'], 'What was taken that day.');
        $this->assertSame(200.0, (float) $summary['totals']['returns'], 'Shown, not silently netted off.');
        $this->assertSame(800.0, (float) $summary['totals']['net']);

        $byProduct = $this->reports()->build(ReportRegistry::SALES_BY_PRODUCT, $this->period());
        $row = $byProduct['rows']->first();

        $this->assertSame(2.0, (float) $row['returned_qty']);
        $this->assertSame(8.0, (float) $row['qty'], 'Net sold, not gross.');
        $this->assertSame(800.0, (float) $row['revenue']);
    }

    public function test_the_reports_and_the_profit_statement_agree_to_the_penny(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        // Tax on the line, and a return, so both adjustments are in play.
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10, 'tax_rate' => 10]]);

        app(SaleReturnService::class)->create($sale, ['reason' => 'Three back'], [
            $sale->items->first()->id => ['quantity' => 3, 'restock' => true],
        ]);

        $statement = app(ProfitService::class)->statement($this->period());
        $summary = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());
        $byProduct = $this->reports()->build(ReportRegistry::SALES_BY_PRODUCT, $this->period());
        $profit = $this->reports()->build(ReportRegistry::PROFIT_SUMMARY, $this->period());

        // The document-level reports reconcile to the statement EXACTLY. They
        // read the same columns and combine them in the same order.
        $this->assertSame($statement['revenue']['net'], (float) $summary['totals']['net']);
        $this->assertSame($statement['revenue']['net'], (float) $profit['totals']['revenue']);
        $this->assertSame($statement['cogs']['net'], (float) $profit['totals']['cost']);
        $this->assertSame($statement['gross_profit'], (float) $profit['totals']['profit']);

        /*
        | A BREAKDOWN is rounded per row, so its column can sit a penny away
        | from the document total — rounding is not associative and a per-row
        | figure that was fudged to make the column tie would be a wrong row.
        | Pinned here so the difference stays a rounding artefact and never
        | grows into a real disagreement.
        */
        $this->assertEqualsWithDelta(
            $statement['revenue']['net'],
            (float) $byProduct['totals']['revenue'],
            0.02,
            'A per-product breakdown drifted from the statement by more than rounding.',
        );
    }

    public function test_tax_is_not_counted_as_revenue(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 10, 'tax_rate' => 10]]);

        $summary = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());

        $this->assertSame(1000.0, (float) $summary['totals']['net'], '1,100 was taken; 100 of it is the tax office\'s.');
        $this->assertSame(100.0, (float) $summary['totals']['tax'], 'Collected, and shown as such.');
    }

    public function test_only_restocked_returns_give_the_cost_back_in_profit_reports(): void
    {
        $this->setUpBusiness();
        $good = $this->stocked('Sound box', cost: 40, price: 100);
        $bad = $this->stocked('Glass jar', cost: 40, price: 100);

        $one = $this->sell([['product_id' => $good->id, 'quantity' => 5]]);
        $two = $this->sell([['product_id' => $bad->id, 'quantity' => 5]]);

        app(SaleReturnService::class)->create($one, ['reason' => 'Unopened'], [
            $one->items->first()->id => ['quantity' => 5, 'restock' => true],
        ]);

        app(SaleReturnService::class)->create($two, ['reason' => 'Smashed'], [
            $two->items->first()->id => ['quantity' => 5, 'restock' => false, 'condition_note' => 'Shattered'],
        ]);

        $report = $this->reports()->build(ReportRegistry::PROFIT_BY_PRODUCT, $this->period());

        $restocked = $this->rowFor($report, 'name', 'Sound box');
        $writtenOff = $this->rowFor($report, 'name', 'Glass jar');

        // Everything came back on both, so revenue is zero on both.
        $this->assertSame(0.0, (float) $restocked['revenue']);
        $this->assertSame(0.0, (float) $writtenOff['revenue']);

        // …but only the sound box came back to the shelf.
        $this->assertSame(0.0, (float) $restocked['cost'], 'Cost reversed with the goods.');
        $this->assertSame(0.0, (float) $restocked['profit']);

        $this->assertSame(200.0, (float) $writtenOff['cost'], 'Paid for, and gone.');
        $this->assertSame(-200.0, (float) $writtenOff['profit'], 'The breakage is a real loss and is reported as one.');
    }

    // ============================================== the shapes of the reports

    public function test_the_summary_keeps_the_quiet_days(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $report = $this->reports()->build(ReportRegistry::SALES_SUMMARY, [
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        // A day the shop was shut is information; dropping it would make the
        // chart look like an unbroken run of trading.
        $this->assertCount(7, $report['rows']);
        $this->assertSame(6, $report['rows']->where('orders', 0)->count());
    }

    public function test_the_summary_can_be_grouped_by_month(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $report = $this->reports()->build(ReportRegistry::SALES_SUMMARY, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'interval' => 'month',
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame(now()->format('Y-m'), $report['rows']->first()['bucket']);
        $this->assertSame('Month', $report['columns'][0]['label']);
    }

    public function test_a_split_payment_is_counted_under_both_methods(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);

        // 300 taken: 120 cash, 180 card. Grouping by the SALE would have to
        // pick one method and be wrong about the other.
        $this->sell([['product_id' => $product->id, 'quantity' => 3]], [], [
            ['method' => 'cash', 'amount' => 120],
            ['method' => 'card', 'amount' => 180],
        ]);

        $report = $this->reports()->build(ReportRegistry::SALES_BY_PAYMENT, $this->period());

        $this->assertSame(120.0, (float) $this->rowFor($report, 'method', 'Cash')['amount']);
        $this->assertSame(180.0, (float) $this->rowFor($report, 'method', 'Card')['amount']);
        $this->assertSame(300.0, (float) $report['totals']['amount']);
        $this->assertSame(100.0, (float) $report['totals']['share']);
    }

    public function test_the_stock_reports_read_the_shelf_as_it_is_now(): void
    {
        $this->setUpBusiness();

        $plenty = $this->stocked('Plenty', quantity: 100);

        // An empty shelf has to be MADE, not declared: a zero movement is
        // refused, so this one is bought in and then sold out.
        $none = $this->stocked('None', quantity: 4);
        $this->sell([['product_id' => $none->id, 'quantity' => 4]]);

        $low = app(ProductService::class)->create([
            'name' => 'Nearly out',
            'type' => ProductType::Standard->value,
            'cost_price' => 10,
            'selling_price' => 20,
            'alert_quantity' => 20,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $low,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 5,
            'unit_cost' => 10,
        ]);

        $lowReport = $this->reports()->build(ReportRegistry::INVENTORY_LOW_STOCK, []);
        $outReport = $this->reports()->build(ReportRegistry::INVENTORY_OUT_OF_STOCK, []);
        $valuation = $this->reports()->build(ReportRegistry::INVENTORY_VALUATION, []);

        $this->assertSame(['Nearly out'], $lowReport['rows']->pluck('name')->all());
        $this->assertSame(15.0, (float) $lowReport['rows']->first()['shortfall'], 'Alert at 20, five on the shelf.');

        $this->assertContains('None', $outReport['rows']->pluck('name')->all());
        $this->assertNotContains('Plenty', $outReport['rows']->pluck('name')->all());

        // 100 × 40 + 5 × 10 = 4,050.
        $this->assertSame(4050.0, (float) $valuation['totals']['value']);

        // A shelf count has no date range — offering one would be a lie about
        // what the number means.
        $this->assertFalse($valuation['meta']['dated']);
    }

    public function test_the_customer_ledger_needs_a_customer(): void
    {
        $this->setUpBusiness();

        try {
            $this->reports()->build(ReportRegistry::CUSTOMERS_LEDGER, $this->period());
            $this->fail('A ledger with no account is a running balance belonging to nobody.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Choose a customer', $e->getMessage());
        }
    }

    public function test_the_customer_ledger_closes_on_the_last_balance_not_a_sum(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);
        $customer = app(CustomerService::class)->create(['name' => 'Account', 'credit_limit' => 100000]);

        $this->sell([['product_id' => $product->id, 'quantity' => 3]], ['customer_id' => $customer->id], []);
        $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['customer_id' => $customer->id], []);

        $report = $this->reports()->build(ReportRegistry::CUSTOMERS_LEDGER, $this->period() + ['customer_id' => $customer->id]);

        $this->assertCount(2, $report['rows']);
        $this->assertSame(500.0, (float) $report['totals']['debit']);
        $this->assertSame(500.0, (float) $report['totals']['balance'], 'The closing balance, not the sum of balances.');
    }

    // ============================================================ filters (#55)

    public function test_the_branch_filter_narrows_and_refuses_what_it_should(): void
    {
        $this->setUpBusiness();

        $depot = app(BranchService::class)->create(['name' => 'Depot', 'code' => 'DEP']);

        $here = $this->stocked('Here', cost: 40, price: 100);
        $there = $this->stocked('There', cost: 20, price: 50, branchId: $depot->id);

        $this->sell([['product_id' => $here->id, 'quantity' => 10]], ['branch_id' => $this->branch->id]);
        $this->sell([['product_id' => $there->id, 'quantity' => 10]], ['branch_id' => $depot->id]);

        $whole = $this->reports()->build(ReportRegistry::SALES_BY_BRANCH, $this->period());
        $mine = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period() + ['branch_id' => $this->branch->id]);

        $this->assertSame(1500.0, (float) $whole['totals']['net']);
        $this->assertSame(1000.0, (float) $mine['totals']['net']);

        // A branch this person cannot reach is refused rather than quietly
        // widened to everything (#48, #138).
        $cashier = $this->userWith([PermissionRegistry::REPORTS_VIEW]);
        $this->actingAs($cashier);
        app(BranchContext::class)->forUser($cashier);

        $this->expectException(HttpException::class);
        $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period() + ['branch_id' => $depot->id]);
    }

    public function test_a_filter_the_report_did_not_ask_for_is_ignored(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 4]]);

        // `sales.by_branch` declares only `period`. Passing an employee id must
        // not silently narrow it — the filter bar and the query have to agree
        // about what question is being answered.
        $report = $this->reports()->build(ReportRegistry::SALES_BY_BRANCH, $this->period() + ['employee_id' => 999999]);

        $this->assertSame(400.0, (float) $report['totals']['net']);
        $this->assertNull($report['meta']['employee_id']);
    }

    public function test_a_backwards_range_is_read_the_right_way_round(): void
    {
        $this->setUpBusiness();

        $report = $this->reports()->build(ReportRegistry::SALES_SUMMARY, [
            'from' => now()->toDateString(),
            'to' => now()->subDays(4)->toDateString(),
        ]);

        $this->assertSame(now()->subDays(4)->toDateString(), $report['meta']['from']);
        $this->assertSame(5, $report['meta']['days']);
    }

    // ============================================================ exports (#56)

    public function test_the_csv_carries_a_title_and_unformatted_numbers(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked('Cola', cost: 40, price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 12]]);

        $response = $this->actingAs($this->owner)->get(
            route('app.reports.export', ['report' => ReportRegistry::SALES_BY_PRODUCT, 'format' => 'csv'] + $this->period())
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // Excel reads a CSV as the system codepage without a byte-order mark.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $this->assertStringContainsString('Sales by product', $csv);
        $this->assertStringContainsString('Cola', $csv);

        // 1,200.00 would be unusable in a spreadsheet: the column could not be
        // summed and the cell would be text.
        $this->assertStringContainsString('1200', $csv);
        $this->assertStringNotContainsString('1,200.00', $csv);
    }

    public function test_the_spreadsheet_is_a_real_xlsx(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $response = $this->actingAs($this->owner)->get(
            route('app.reports.export', ['report' => ReportRegistry::SALES_BY_PRODUCT, 'format' => 'xlsx'] + $this->period())
        );

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;

        $this->assertTrue($zip->open($path) === true, 'The file is not a readable ZIP.');

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertNotFalse($zip->locateName($part), "Missing {$part}.");
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Sales by product', $sheet);
        // Numbers must be numbers, or Excel cannot add the column up.
        $this->assertStringContainsString('<v>200</v>', $sheet);
    }

    public function test_the_pdf_is_a_pdf(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $response = $this->actingAs($this->owner)->get(
            route('app.reports.export', ['report' => ReportRegistry::SALES_BY_PRODUCT, 'format' => 'pdf'] + $this->period())
        );

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_paid_formats_follow_the_plan_and_csv_does_not(): void
    {
        $this->setUpBusiness([
            FeatureRegistry::REPORTS_EXPORT_PDF => false,
            FeatureRegistry::REPORTS_EXPORT_EXCEL => false,
        ]);

        $args = ['report' => ReportRegistry::SALES_BY_PRODUCT] + $this->period();

        // CSV is free: a report you cannot get out of the system is a report
        // you cannot check.
        $this->actingAs($this->owner)
            ->get(route('app.reports.export', $args + ['format' => 'csv']))
            ->assertOk();

        $this->actingAs($this->owner)
            ->get(route('app.reports.export', $args + ['format' => 'xlsx']))
            ->assertRedirect(route('app.billing.index'));

        $this->actingAs($this->owner)
            ->get(route('app.reports.export', $args + ['format' => 'pdf']))
            ->assertRedirect(route('app.billing.index'));
    }

    public function test_an_unknown_export_format_is_not_found(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->get(route('app.reports.export', ['report' => ReportRegistry::SALES_SUMMARY, 'format' => 'docx']))
            ->assertNotFound();
    }

    // ================================================== through the interface

    public function test_the_catalogue_lists_only_what_this_shop_and_person_can_open(): void
    {
        $this->setUpBusiness();

        // The owner sees everything the plan includes, profit reports included.
        $this->actingAs($this->owner)
            ->get(route('app.reports.index'))
            ->assertOk()
            ->assertSee('Sales summary')
            ->assertSee('Profit by product')
            ->assertSee('Expense summary');

        // A viewer without `reports.view_profit` never sees the margin reports
        // listed — a greyed-out row would only tell them what they cannot have.
        $viewer = $this->userWith([PermissionRegistry::REPORTS_VIEW]);

        $this->actingAs($viewer)
            ->get(route('app.reports.index'))
            ->assertOk()
            ->assertSee('Sales summary')
            ->assertDontSee('Profit by product');
    }

    public function test_a_report_beyond_a_role_is_refused_even_by_its_url(): void
    {
        $this->setUpBusiness();

        $viewer = $this->userWith([PermissionRegistry::REPORTS_VIEW]);

        $this->actingAs($viewer)
            ->get(route('app.reports.show', ReportRegistry::SALES_SUMMARY))
            ->assertOk();

        // Hiding the link is a courtesy; this is the guard.
        $this->actingAs($viewer)
            ->get(route('app.reports.show', ReportRegistry::PROFIT_BY_PRODUCT))
            ->assertForbidden();
    }

    public function test_a_report_the_plan_does_not_include_sends_the_owner_to_billing(): void
    {
        $this->setUpBusiness([FeatureRegistry::REPORTS_ADVANCED => false]);

        // Basic reports still work…
        $this->actingAs($this->owner)
            ->get(route('app.reports.show', ReportRegistry::SALES_SUMMARY))
            ->assertOk();

        // …the advanced one is a plan question, so it goes to billing.
        $this->actingAs($this->owner)
            ->get(route('app.reports.show', ReportRegistry::SALES_BY_EMPLOYEE))
            ->assertRedirect(route('app.billing.index'));

        $this->actingAs($this->owner)
            ->get(route('app.reports.index'))
            ->assertOk()
            ->assertDontSee('Sales by employee');
    }

    public function test_exporting_is_its_own_permission(): void
    {
        $this->setUpBusiness();

        $reader = $this->userWith([PermissionRegistry::REPORTS_VIEW]);

        $this->actingAs($reader)
            ->get(route('app.reports.show', ReportRegistry::SALES_SUMMARY))
            ->assertOk();

        // An export leaves with the person who made it and outlives their
        // account, which is not the same authority as reading a screen.
        $this->actingAs($reader)
            ->get(route('app.reports.export', ['report' => ReportRegistry::SALES_SUMMARY, 'format' => 'csv']))
            ->assertRedirect()
            ->assertSessionHas('permission_denied');
    }

    public function test_the_report_screen_renders_its_table_and_totals(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked('Cola 500ml', cost: 40, price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 7]]);

        $this->actingAs($this->owner)
            ->get(route('app.reports.show', ReportRegistry::SALES_BY_PRODUCT))
            ->assertOk()
            ->assertSee('Sales by product')
            ->assertSee('Cola 500ml')
            ->assertSee('700.00');
    }

    // =========================================================== isolation

    public function test_another_shops_figures_are_not_in_these_reports(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(cost: 40, price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 6]]);

        $mine = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());
        $this->assertSame(600.0, (float) $mine['totals']['net']);

        // ⚠️ The tenant stamp has to come off before building the second shop.
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

        $theirs = $this->reports()->build(ReportRegistry::SALES_SUMMARY, $this->period());

        $this->assertSame(0.0, (float) $theirs['totals']['net'], 'A new shop has sold nothing.');
        $this->assertSame(0.0, (float) $theirs['totals']['orders']);
    }

    // ============================================================== expenses

    public function test_the_expense_reports_read_the_same_figures_as_the_expense_book(): void
    {
        $this->setUpBusiness();

        $rent = app(ExpenseService::class)->createCategory(['name' => 'Rent expense']);
        $fuel = app(ExpenseService::class)->createCategory(['name' => 'Fuel expense']);

        foreach ([[$rent, 900], [$fuel, 300], [$fuel, 200]] as [$category, $amount]) {
            app(ExpenseService::class)->create([
                'expense_category_id' => $category->id,
                'amount' => $amount,
                'payment_method' => 'bank_transfer',
            ]);
        }

        $byCategory = $this->reports()->build(ReportRegistry::EXPENSES_BY_CATEGORY, $this->period());

        $this->assertSame(1400.0, (float) $byCategory['totals']['amount']);
        $this->assertSame('Rent expense', $byCategory['rows']->first()['name'], 'Biggest first.');
        $this->assertSame(64.3, (float) $byCategory['rows']->first()['share']);

        $summary = $this->reports()->build(ReportRegistry::EXPENSES_SUMMARY, $this->period());
        $this->assertSame(1400.0, (float) $summary['totals']['amount']);
        $this->assertSame(3.0, (float) $summary['totals']['entries']);

        // And they agree with the P&L, which is the only reason to trust either.
        $this->assertSame(
            app(ProfitService::class)->statement($this->period())['expenses']['total'],
            (float) $summary['totals']['amount'],
        );
    }

    public function test_expense_reports_need_the_expense_permission_as_well(): void
    {
        $this->setUpBusiness();

        // `reports.view` opens the section; the expense reports also want
        // `expenses.view`, because what a shop spends is its own business.
        $viewer = $this->userWith([PermissionRegistry::REPORTS_VIEW]);

        $this->actingAs($viewer)
            ->get(route('app.reports.show', ReportRegistry::EXPENSES_BY_CATEGORY))
            ->assertForbidden();

        $bookkeeper = $this->userWith([PermissionRegistry::REPORTS_VIEW, PermissionRegistry::EXPENSES_VIEW]);

        $this->actingAs($bookkeeper)
            ->get(route('app.reports.show', ReportRegistry::EXPENSES_BY_CATEGORY))
            ->assertOk();
    }
}
