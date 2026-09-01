<?php

namespace Tests\Feature\Performance;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query budgets for the screens people open all day (#97, #167).
 *
 * ================= WHY A BUDGET AND NOT A COUNT =================
 * These tests do not assert an exact number — that would fail on every harmless
 * refactor and be edited until it meant nothing. They assert a CEILING, chosen
 * to be comfortably above what the screen does today and comfortably below what
 * an N+1 would cost.
 *
 * The point is the SHAPE of the growth, not the constant: each screen is
 * rendered with a handful of rows and then with several times as many, and the
 * query count must not move. That is the only thing that distinguishes "this
 * page does twelve queries" from "this page does one query per row", and the
 * second one is invisible on a demo and fatal in a real shop.
 *
 * ⚠️ When one of these fails, the fix is an eager load — not a bigger budget.
 */
class QueryBudgetTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Query Budget Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach ([
            LimitRegistry::PRODUCTS => 1000,
            LimitRegistry::CATEGORIES => 100,
            LimitRegistry::BRANDS => 100,
            LimitRegistry::CUSTOMERS => 1000,
            LimitRegistry::SUPPLIERS => 100,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 50,
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

    /**
     * Run a request and count the queries it makes.
     *
     * @return array{count: int, queries: list<string>}
     */
    protected function measure(string $url): array
    {
        // The settings and entitlement caches are warmed by the first request in
        // a real session too, so measuring a cold one would count queries a user
        // pays for once and then never again.
        $this->actingAs($this->owner)->get($url);

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($this->owner)->get($url);
        $response->assertOk();

        DB::flushQueryLog();

        return ['count' => count($queries), 'queries' => $queries];
    }

    protected function stocked(string $name): Product
    {
        $product = app(ProductService::class)->create([
            'name' => $name,
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 100,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 50,
            'unit_cost' => 40,
        ]);

        return $product;
    }

    protected function seedSales(int $count): void
    {
        $product = $this->stocked('Cola '.fake()->unique()->numerify('###'));

        for ($i = 0; $i < $count; $i++) {
            $customer = app(CustomerService::class)->create([
                'name' => 'Customer '.$i,
                'credit_limit' => 10000,
            ]);

            app(SaleService::class)->complete(
                ['customer_id' => $customer->id],
                [['product_id' => $product->id, 'quantity' => 1]],
                [['method' => 'cash', 'amount' => 1000]],
            );
        }
    }

    /**
     * The assertion that actually matters: rendering five times as many rows
     * must not cost five times as many queries.
     */
    protected function assertFlat(string $url, callable $addRows, int $budget): void
    {
        $addRows(3);
        $small = $this->measure($url);

        $addRows(15);
        $large = $this->measure($url);

        $this->assertLessThanOrEqual(
            $budget,
            $large['count'],
            sprintf(
                "%s took %d queries for 18 rows (budget %d).\nThe fix is an eager load, not a bigger budget.\n%s",
                $url,
                $large['count'],
                $budget,
                implode("\n", array_slice(array_unique($large['queries']), 0, 12)),
            ),
        );

        $this->assertLessThanOrEqual(
            $small['count'] + 2,
            $large['count'],
            sprintf(
                '%s grew from %d to %d queries when the rows went from 3 to 18 — that is an N+1.',
                $url,
                $small['count'],
                $large['count'],
            ),
        );
    }

    // ================================================== the screens (#97, #167)

    public function test_the_sales_book_does_not_query_per_row(): void
    {
        $this->assertFlat(
            route('app.sales.index'),
            fn (int $n) => $this->seedSales($n),
            budget: 40,
        );
    }

    public function test_the_product_list_does_not_query_per_row(): void
    {
        $this->assertFlat(
            route('app.products.index'),
            fn (int $n) => collect(range(1, $n))->each(fn ($i) => $this->stocked('Product '.fake()->unique()->numerify('####'))),
            budget: 40,
        );
    }

    public function test_the_dashboard_holds_its_budget_as_the_shop_grows(): void
    {
        // The dashboard is the most-opened screen in the system and the one
        // that touches the most modules, so it gets the most room — but it must
        // still not grow with the data.
        $this->assertFlat(
            route('app.dashboard'),
            fn (int $n) => $this->seedSales($n),
            budget: 60,
        );
    }

    public function test_a_report_is_aggregates_rather_than_rows(): void
    {
        $this->seedSales(20);

        $result = $this->measure(route('app.reports.show', 'sales.by_product'));

        // A report that loaded its rows to add them up would be dozens of
        // queries and megabytes of memory on a year of data (#183).
        $this->assertLessThanOrEqual(
            30,
            $result['count'],
            'A report should be aggregates, not rows: '.$result['count'].' queries.',
        );
    }

    public function test_the_customer_list_does_not_query_per_row(): void
    {
        $this->assertFlat(
            route('app.customers.index'),
            fn (int $n) => collect(range(1, $n))->each(
                fn ($i) => app(CustomerService::class)->create(['name' => 'Buyer '.fake()->unique()->numerify('####')])
            ),
            budget: 40,
        );
    }
}
