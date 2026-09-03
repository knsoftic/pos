<?php

namespace Tests\Feature\Reporting;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\ReportService;
use App\Support\BranchContext;
use App\Support\ReportRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "What did the stock cost, what will it fetch, and what is the difference?"
 *
 * ⚠️ THE PROFIT HERE IS NOT EARNED PROFIT. Nothing has been sold. It is what
 * the shelf WOULD make at today's price if all of it went at full price, and
 * reality subtracts discounts, breakage and whatever never sells. Realised
 * profit is the Profit reports, which count actual sales at the cost that was
 * snapshotted when they happened.
 *
 * The two must never be confused, which is why they are separate reports with
 * separate words on them.
 */
class StockValuationTest extends TestCase
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

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();
    }

    protected function stocked(string $name, float $cost, float $price, float $qty): void
    {
        $product = app(ProductService::class)->create([
            'name' => $name,
            'type' => ProductType::Standard->value,
            'cost_price' => $cost,
            'selling_price' => $price,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => $qty,
            'unit_cost' => $cost,
        ]);
    }

    public function test_it_answers_all_four_questions_at_once(): void
    {
        // 10 × cost 40 = 400 in;  10 × price 100 = 1,000 out;  600 to make.
        $this->stocked('Cola 500ml', cost: 40, price: 100, qty: 10);

        $report = app(ReportService::class)->build(ReportRegistry::INVENTORY_VALUATION);
        $row = collect($report['rows'])->firstWhere('name', 'Cola 500ml');

        $this->assertSame(10.0, $row['quantity'], 'kitna stock');
        $this->assertSame(400.0, $row['value'], 'kitne ka aaya');
        $this->assertSame(1000.0, $row['retail'], 'kitne ka bikega');
        $this->assertSame(600.0, $row['profit'], 'profit kitna');
        $this->assertSame(60.0, $row['margin']);
    }

    public function test_a_shelf_priced_below_cost_shows_a_loss_and_sorts_first(): void
    {
        $this->stocked('Healthy Seller', cost: 40, price: 100, qty: 10);

        // ⚠️ Bought at 90, being sold at 70. Every one of these loses 20, and
        // the shop has no other way of noticing until the month is over.
        $this->stocked('Priced Below Cost', cost: 90, price: 70, qty: 5);

        $report = app(ReportService::class)->build(ReportRegistry::INVENTORY_VALUATION);

        $loss = collect($report['rows'])->firstWhere('name', 'Priced Below Cost');
        $this->assertSame(-100.0, $loss['profit'], '5 × (70 − 90)');

        // Sorted to the top, because a losing line is the reason to open this
        // report at all — buried under the profitable ones it is never seen.
        $this->assertSame('Priced Below Cost', $report['rows'][0]['name']);
    }

    public function test_the_totals_are_the_whole_shelf(): void
    {
        $this->stocked('One', cost: 40, price: 100, qty: 10);   // 400 → 1000
        $this->stocked('Two', cost: 90, price: 70, qty: 5);     // 450 →  350

        $report = app(ReportService::class)->build(ReportRegistry::INVENTORY_VALUATION);

        $this->assertSame(850.0, $report['totals']['value']);
        $this->assertSame(1350.0, $report['totals']['retail']);

        // The loser drags the total down, which is the honest arithmetic.
        $this->assertSame(500.0, $report['totals']['profit']);
    }

    public function test_the_report_opens_over_http(): void
    {
        $this->stocked('Cola 500ml', cost: 40, price: 100, qty: 10);

        $this->actingAs($this->owner)
            ->get(route('app.reports.show', ReportRegistry::INVENTORY_VALUATION))
            ->assertOk()
            ->assertSee('Potential profit')
            ->assertSee('Retail value');
    }
}
