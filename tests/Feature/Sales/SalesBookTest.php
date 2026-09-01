<?php

namespace Tests\Feature\Sales;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
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
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
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
 * The sales book and the receipt (#21, #23, #143, #144, #145).
 *
 * The decisions defended here:
 *   1. `sales.view` shows YOUR sales; `sales.view_all` shows everyone's — and
 *      the narrowing happens in the query, not by hiding fetched rows.
 *   2. HELD SALES ARE NOT SALES. They never appear in the book, because nothing
 *      has happened yet and a day's takings would read too high.
 *   3. A REPRINT IS COUNTED (#143), and says on its face that it is a copy.
 *   4. PROFIT IS A PERMISSION (#52), on the list and on the sale.
 */
class SalesBookTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Sales Book Shop']);
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
    }

    protected function stocked(float $price = 70): Product
    {
        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => $price,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 200,
            'unit_cost' => 40,
        ]);

        return $product;
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

    protected function sell(Product $product, float $quantity = 1, ?User $as = null): Sale
    {
        $previous = auth('web')->user();

        if ($as !== null) {
            $this->actingAs($as);
            app(BranchContext::class)->forUser($as);
        }

        $sale = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ], [['method' => 'cash', 'amount' => 100000]]);

        if ($as !== null && $previous !== null) {
            $this->actingAs($previous);
            app(BranchContext::class)->forUser($previous);
        }

        return $sale;
    }

    // ================================================== the book (#21)

    public function test_the_book_lists_completed_sales_with_their_totals(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $product = $this->stocked(price: 70);
        $sale = $this->sell($product, 3);

        $this->actingAs($this->owner)
            ->get(route('app.sales.index'))
            ->assertOk()
            ->assertSee($sale->invoice_no)
            ->assertSee('210.00');
    }

    public function test_held_sales_never_appear_in_the_book(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $product = $this->stocked();
        $held = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 2]]);

        $this->actingAs($this->owner)
            ->get(route('app.sales.index'))
            ->assertOk()
            ->assertDontSee($held->invoice_no);

        // Nothing has happened yet, so it is not takings.
        $this->assertStringStartsWith('HOLD-', $held->invoice_no);
    }

    public function test_a_cashier_sees_only_their_own_sales(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked();

        $cashier = $this->userWith([
            PermissionRegistry::SALES_VIEW,
            PermissionRegistry::POS_OPERATE,
        ]);

        $theirs = $this->sell($product, 1, as: $cashier);
        $someoneElses = $this->sell($product, 1, as: $this->owner);

        $this->actingAs($cashier)
            ->get(route('app.sales.index'))
            ->assertOk()
            ->assertSee($theirs->invoice_no)
            ->assertDontSee($someoneElses->invoice_no);

        // And they cannot open it by guessing the URL either.
        $this->actingAs($cashier)->get(route('app.sales.show', $someoneElses))->assertNotFound();
    }

    public function test_someone_with_view_all_sees_the_whole_shop(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked();

        $cashier = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::POS_OPERATE]);
        $theirs = $this->sell($product, 1, as: $cashier);

        $manager = $this->userWith([
            PermissionRegistry::SALES_VIEW,
            PermissionRegistry::SALES_VIEW_ALL,
        ]);

        $this->actingAs($manager)
            ->get(route('app.sales.index'))
            ->assertOk()
            ->assertSee($theirs->invoice_no);
    }

    public function test_the_book_can_be_filtered_by_date(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked();

        $today = $this->sell($product, 1);

        // A sale from last week, dated directly since the till only sells today.
        $old = $this->sell($product, 1);
        $old->update(['sale_date' => now()->subWeek()->toDateString()]);

        $response = $this->actingAs($this->owner)
            ->get(route('app.sales.index', ['from' => now()->toDateString()]));

        $response->assertOk()->assertSee($today->invoice_no)->assertDontSee($old->invoice_no);
    }

    public function test_profit_is_hidden_from_someone_who_may_not_see_cost(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked(price: 100);
        $sale = $this->sell($product, 2);

        // The owner sees it…
        $this->actingAs($this->owner)
            ->get(route('app.sales.show', $sale))
            ->assertOk()
            ->assertSee('Gross profit');

        // …a cashier does not.
        $cashier = $this->userWith([
            PermissionRegistry::SALES_VIEW,
            PermissionRegistry::SALES_VIEW_ALL,
        ]);

        $this->actingAs($cashier)
            ->get(route('app.sales.show', $sale))
            ->assertOk()
            ->assertDontSee('Gross profit');
    }

    // =============================================== the receipt (#23)

    public function test_the_receipt_prints_at_all_three_widths(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked(price: 70);
        $sale = $this->sell($product, 2);

        foreach (['58mm', '80mm', 'a4'] as $width) {
            $this->actingAs($this->owner)
                ->get(route('app.sales.receipt', [$sale, 'width' => $width]))
                ->assertOk()
                ->assertSee($sale->invoice_no)
                ->assertSee('140.00')
                ->assertSee('Cola 500ml');
        }
    }

    public function test_an_unknown_width_falls_back_rather_than_breaking(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $sale = $this->sell($this->stocked(), 1);

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', [$sale, 'width' => 'billboard']))
            ->assertOk()
            ->assertSee($sale->invoice_no);
    }

    public function test_the_receipt_carries_the_shops_own_footer(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        config(['pos.receipt.footer' => 'Exchanges within 7 days with this receipt.']);

        $sale = $this->sell($this->stocked(), 1);

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertSee('Exchanges within 7 days with this receipt.');
    }

    public function test_a_reprint_is_counted_and_says_it_is_a_copy(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $sale = $this->sell($this->stocked(), 1);

        // The first print is not a reprint.
        $this->actingAs($this->owner)->get(route('app.sales.receipt', $sale))->assertOk()->assertDontSee('REPRINT');
        $this->assertSame(0, $sale->fresh()->print_count);

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', [$sale, 'reprint' => 1]))
            ->assertOk();

        $this->assertSame(1, $sale->fresh()->print_count);

        // The next one says on its face that it is a copy — two identical
        // invoices in circulation is what #143 guards against.
        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertSee('REPRINT');

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $this->business->id,
            'event' => 'sale.reprinted',
        ]);
    }

    public function test_a_voided_sale_says_so_on_the_receipt(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $sale = $this->sell($this->stocked(), 1);

        app(SaleService::class)->void($sale, 'Rung up twice');

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale->fresh()))
            ->assertOk()
            ->assertSee('VOIDED');
    }

    // ================================================== voiding (#198)

    public function test_voiding_from_the_screen_needs_permission_and_a_reason(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);
        $product = $this->stocked();
        $sale = $this->sell($product, 4);

        $viewer = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::SALES_VIEW_ALL]);

        $this->actingAs($viewer)
            ->postJson(route('app.sales.void', $sale), ['reason' => 'Nope'])
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->post(route('app.sales.void', $sale), [])
            ->assertSessionHasErrors('reason');

        $before = app(InventoryService::class)->getAvailableStock($product);

        $this->actingAs($this->owner)
            ->post(route('app.sales.void', $sale), ['reason' => 'Customer changed their mind'])
            ->assertRedirect();

        $this->assertSame(SaleStatus::Voided, $sale->fresh()->status);
        $this->assertSame($before + 4, app(InventoryService::class)->getAvailableStock($product));
    }

    // ------------------------------------------------------------- gates

    public function test_the_book_needs_the_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::SALES_INVOICING => false]);
        $this->actingAs($this->owner);

        $this->actingAs($this->owner)->getJson(route('app.sales.index'))->assertStatus(403);
    }

    public function test_another_businesss_sale_is_out_of_reach(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Sale::factory()->create(),
        );

        $this->actingAs($this->owner)->get(route('app.sales.show', $stranger))->assertNotFound();
        $this->actingAs($this->owner)->get(route('app.sales.receipt', $stranger))->assertNotFound();
    }

    public function test_a_credit_sale_shows_what_is_still_owed(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $product = $this->stocked(price: 100);
        $customer = app(CustomerService::class)->create(['name' => 'Account Customer', 'credit_limit' => 5000]);

        $sale = app(SaleService::class)->complete(
            ['customer_id' => $customer->id],
            [['product_id' => $product->id, 'quantity' => 5]],
            [['method' => 'cash', 'amount' => 200]],
        );

        $this->actingAs($this->owner)
            ->get(route('app.sales.show', $sale))
            ->assertOk()
            ->assertSee('On account')
            ->assertSee('300.00');

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertSee('On account');
    }
}
