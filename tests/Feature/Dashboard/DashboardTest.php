<?php

namespace Tests\Feature\Dashboard;

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
use App\Services\CustomerService;
use App\Services\DashboardService;
use App\Services\GlobalSearchService;
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
 * The dashboard, quick actions, activity feed and global search
 * (#12, #75, #123, #124).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE DASHBOARD AGREES WITH THE REPORTS. It reads the same definitions, so
 *     an owner never has two numbers and no way to choose.
 *  2. A CARD SOMEBODY MAY NOT SEE IS ABSENT, NOT ZERO. Showing a cashier "0"
 *     for gross profit is a lie they might repeat; showing the real figure is a
 *     leak (#52, #188).
 *  3. SEARCH IS NOT A BACK DOOR. Every source is gated on the permission that
 *     guards its own screen, so results are only what the person could have
 *     navigated to anyway.
 */
class DashboardTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Dashboard Test Shop']);
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

    protected function stocked(string $name = 'Cola 500ml', float $price = 100): Product
    {
        $product = app(ProductService::class)->create([
            'name' => $name,
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => $price,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 100,
            'unit_cost' => 40,
        ]);

        return $product;
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function sell(array $lines, ?User $as = null): Sale
    {
        $previous = auth('web')->user();

        if ($as !== null) {
            $this->actingAs($as);
            app(BranchContext::class)->forUser($as);
        }

        $sale = app(SaleService::class)->complete([], $lines, [['method' => 'cash', 'amount' => 1000000]]);

        if ($as !== null && $previous !== null) {
            $this->actingAs($previous);
            app(BranchContext::class)->forUser($previous);
        }

        return $sale;
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

    /** @return array<string, mixed>|null */
    protected function card(array $data, string $key): ?array
    {
        return collect($data['cards'])->firstWhere('key', $key);
    }

    // ================================================ the figures agree (#12)

    public function test_the_dashboard_reads_the_same_takings_the_reports_do(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        $this->sell([['product_id' => $product->id, 'quantity' => 7]]);

        $data = app(DashboardService::class)->build();
        $statement = app(ProfitService::class)->statement([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(700.0, (float) $this->card($data, 'today')['value']);
        $this->assertSame($statement['revenue']['net'], (float) $this->card($data, 'today')['value']);
        $this->assertSame($statement['gross_profit'], (float) $this->card($data, 'profit')['value']);
    }

    public function test_a_return_comes_off_the_takings_on_the_dashboard_too(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 10]]);

        app(SaleReturnService::class)->create($sale, ['reason' => 'Three back'], [
            $sale->items->first()->id => ['quantity' => 3, 'restock' => true],
        ]);

        $data = app(DashboardService::class)->build();

        $this->assertSame(700.0, (float) $this->card($data, 'today')['value'], '1,000 sold less 300 returned.');
    }

    public function test_a_card_somebody_may_not_see_is_absent_rather_than_zero(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 5]]);

        // A cashier can see the till and the sales book, and nothing about
        // margin — the most sensitive number on the screen (#52).
        $cashier = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::POS_OPERATE]);
        $this->actingAs($cashier);

        $data = app(DashboardService::class)->build();

        $this->assertNotNull($this->card($data, 'today'), 'They may see the sales book, so they see takings.');
        $this->assertNull($this->card($data, 'profit'), 'A zero here would be a lie they might repeat.');
        $this->assertNull($this->card($data, 'expenses'));
        $this->assertNull($this->card($data, 'receivable'));
    }

    public function test_a_plan_without_profit_and_loss_hides_the_profit_card(): void
    {
        $this->setUpBusiness([FeatureRegistry::ACCOUNTING_PROFIT_LOSS => false]);
        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->assertNull($this->card(app(DashboardService::class)->build(), 'profit'));
    }

    public function test_the_period_filter_narrows_the_figures(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 4]]);

        // A window that ended before today saw nothing.
        $data = app(DashboardService::class)->build([
            'from' => now()->subDays(10)->toDateString(),
            'to' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertSame(0.0, (float) $this->card($data, 'period')['value']);
        $this->assertSame(400.0, (float) $this->card($data, 'today')['value'], 'Today is always today.');
    }

    public function test_a_very_wide_range_is_capped_rather_than_refused(): void
    {
        $this->setUpBusiness();

        // A chart of 400 bars is a smear, so the range is trimmed and still
        // answers something useful rather than erroring.
        $data = app(DashboardService::class)->build([
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertLessThanOrEqual(93, $data['period']['days']);
    }

    // =========================================== quick actions & activity

    public function test_quick_actions_only_offer_what_the_person_can_do(): void
    {
        $this->setUpBusiness();

        $labels = collect(app(DashboardService::class)->build()['actions'])->pluck('label');

        $this->assertContains('New sale', $labels->all());
        $this->assertContains('Add a product', $labels->all());

        // An action you can see and cannot take is worse than one that is not
        // there.
        $cashier = $this->userWith([PermissionRegistry::POS_OPERATE]);
        $this->actingAs($cashier);

        $labels = collect(app(DashboardService::class)->build()['actions'])->pluck('label');

        $this->assertContains('New sale', $labels->all());
        $this->assertNotContains('Add a product', $labels->all());
        $this->assertNotContains('Record an expense', $labels->all());
    }

    public function test_the_activity_feed_respects_whose_sales_you_may_see(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $cashier = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::POS_OPERATE]);

        $mine = $this->sell([['product_id' => $product->id, 'quantity' => 1]], as: $cashier);
        $theirs = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->actingAs($cashier);
        $titles = collect(app(DashboardService::class)->build()['activity'])->pluck('title');

        // `sales.view` is your own; `sales.view_all` is everybody's — narrowed
        // in the query, exactly as the sales book does it (#21).
        $this->assertContains($mine->invoice_no, $titles->all());
        $this->assertNotContains($theirs->invoice_no, $titles->all());
    }

    public function test_the_setup_list_empties_itself_and_then_disappears(): void
    {
        $this->setUpBusiness();

        $steps = app(DashboardService::class)->build()['setup'];
        $this->assertNotEmpty($steps, 'A brand-new shop is told what to do next.');
        $this->assertFalse(collect($steps)->firstWhere('label', 'Add your first products')['done']);

        $product = $this->stocked();
        $this->sell([['product_id' => $product->id, 'quantity' => 1]]);
        $this->userWith([PermissionRegistry::POS_OPERATE]);

        // Everything ticked means the list stops appearing — a checklist of
        // ticks is clutter on a screen somebody opens every morning.
        $this->assertSame([], app(DashboardService::class)->build()['setup']);
    }

    public function test_the_screen_renders_with_its_cards_and_actions(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);
        $this->sell([['product_id' => $product->id, 'quantity' => 3]]);

        $this->actingAs($this->owner)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('300.00')
            ->assertSee('New sale')
            ->assertSee('What just happened');
    }

    // =============================================== global search (#75)

    public function test_search_finds_things_by_name_sku_and_barcode(): void
    {
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Mineral Water 1.5L',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 80,
            'barcode' => '9012345678906',
        ]);

        $search = app(GlobalSearchService::class);

        $this->assertSame('Mineral Water 1.5L', $search->search('Mineral')->first()['title']);
        $this->assertSame('Mineral Water 1.5L', $search->search($product->sku)->first()['title']);

        // A barcode is scanned in full — an exact match, and one that can use
        // the index.
        $this->assertSame('Mineral Water 1.5L', $search->search('9012345678906')->first()['title']);
    }

    public function test_search_finds_customers_and_invoices(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        app(CustomerService::class)->create(['name' => 'Ayesha Traders', 'credit_limit' => 10000]);
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $search = app(GlobalSearchService::class);

        $this->assertSame('Customers', $search->search('Ayesha')->first()['group']);
        $this->assertSame($sale->invoice_no, $search->search($sale->invoice_no)->first()['title']);
    }

    public function test_search_is_not_a_back_door_into_a_module(): void
    {
        $this->setUpBusiness();
        $this->stocked('Secret Product');
        app(CustomerService::class)->create(['name' => 'Secret Customer', 'credit_limit' => 1000]);

        // Somebody who may only work the till sees neither.
        $cashier = $this->userWith([PermissionRegistry::POS_OPERATE]);
        $this->actingAs($cashier);

        $results = app(GlobalSearchService::class)->search('Secret');

        $this->assertCount(0, $results, 'A friendly interface is still a leak.');
    }

    public function test_search_ignores_a_term_too_short_to_mean_anything(): void
    {
        $this->setUpBusiness();
        $this->stocked('Cola 500ml');

        // One letter matches half a catalogue; that is noise, not a search.
        $this->assertCount(0, app(GlobalSearchService::class)->search('C'));
        $this->assertGreaterThan(0, app(GlobalSearchService::class)->search('Co')->count());
    }

    public function test_search_never_crosses_a_tenant(): void
    {
        $this->setUpBusiness();
        $this->stocked('Mine Only');

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

        $this->assertCount(0, app(GlobalSearchService::class)->search('Mine Only'));
    }

    public function test_the_search_endpoint_groups_what_it_returns(): void
    {
        $this->setUpBusiness();
        $this->stocked('Cola 500ml');

        $response = $this->actingAs($this->owner)->getJson(route('app.search', ['q' => 'Cola']));

        $response->assertOk();
        $response->assertJsonPath('term', 'Cola');
        $response->assertJsonStructure(['term', 'count', 'groups']);
        $this->assertSame('Cola 500ml', $response->json('groups.Products.0.title'));
    }
}
