<?php

namespace Tests\Feature\Sales;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Goods coming back from a customer (#53, #140).
 *
 * What these tests exist to protect:
 *   1. NOTHING COMES BACK TWICE. The limit is what was sold less what has
 *      already been returned, and it is checked before anything is written.
 *   2. RESTOCKING IS PER LINE. A smashed item is still refunded, but it does
 *      NOT go back on the shelf — otherwise every breakage quietly inflates
 *      stock and the shop finds out at the next count.
 *   3. THE MONEY GOES SOMEWHERE SENSIBLE BY DEFAULT. An account customer is
 *      credited, a walk-in is handed cash, and the impossible combination is
 *      refused rather than guessed at.
 *   4. PROFIT REVERSES AT THE SALE'S OWN COST (#52), not today's.
 *   5. RETURNING AND VOIDING ARE MUTUALLY EXCLUSIVE. Doing both would put the
 *      goods back twice and hand the money back twice.
 *   6. REFUNDING IS ITS OWN PERMISSION (#140).
 */
class SaleReturnTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Returns Test Shop']);
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

    protected function returns(): SaleReturnService
    {
        return app(SaleReturnService::class);
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    protected function stocked(float $quantity = 100, float $cost = 40, float $price = 70): Product
    {
        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => $cost,
            'selling_price' => $price,
        ]);

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

    // ============================================ what a return actually does

    public function test_a_return_puts_the_goods_back_and_hands_the_money_over(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(quantity: 100, cost: 40, price: 70);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 3]]);
        $this->assertSame(97.0, $this->inventory()->getAvailableStock($product));

        $return = $this->returns()->create($sale, ['reason' => 'Wrong size'], [
            $sale->items->first()->id => ['quantity' => 2, 'restock' => true],
        ]);

        $this->assertSame(140.0, (float) $return->total, '2 × 70.');
        $this->assertSame(99.0, $this->inventory()->getAvailableStock($product), 'Two went back on the shelf.');

        $movement = StockMovement::query()->allBranches()
            ->where('type', StockMovementType::SaleReturn)->firstOrFail();

        $this->assertSame(2.0, (float) $movement->quantity, 'A return is stock coming IN.');
        $this->assertSame($return->getMorphClass(), $movement->reference_type);
        $this->assertSame($return->id, $movement->reference_id);
    }

    public function test_the_sale_is_never_rewritten_by_a_return(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 4]]);

        $this->returns()->create($sale, ['reason' => 'Changed their mind'], [
            $sale->items->first()->id => ['quantity' => 4],
        ]);

        $sale->refresh()->load('items');

        $this->assertSame(SaleStatus::Completed, $sale->status, 'A returned sale still happened.');
        $this->assertSame(280.0, (float) $sale->total, 'Its total is what was taken that day.');
        $this->assertSame(4.0, (float) $sale->items->first()->quantity);
        $this->assertTrue($sale->isFullyReturned());
    }

    // ================================================ nothing comes back twice

    public function test_more_cannot_come_back_than_went_out(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('only 2 can be returned');

        $this->returns()->create($sale, ['reason' => 'Too many'], [
            $sale->items->first()->id => ['quantity' => 3],
        ]);
    }

    public function test_returns_accumulate_against_the_same_line(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 5]]);
        $item = $sale->items->first();

        $this->returns()->create($sale, ['reason' => 'First two'], [$item->id => ['quantity' => 2]]);

        $item->refresh();
        $this->assertSame(2.0, $item->returnedQuantity());
        $this->assertSame(3.0, $item->returnableQuantity());

        $this->returns()->create($sale, ['reason' => 'Two more'], [$item->id => ['quantity' => 2]]);

        $item->refresh();
        $this->assertSame(1.0, $item->returnableQuantity());

        // The fifth is the last one that can come back.
        $this->expectException(HttpException::class);
        $this->returns()->create($sale, ['reason' => 'Two too many'], [$item->id => ['quantity' => 2]]);
    }

    public function test_a_line_over_the_limit_stops_the_whole_return(): void
    {
        $this->setUpBusiness();
        $good = $this->stocked();
        $bad = app(ProductService::class)->create([
            'name' => 'Crisps',
            'type' => ProductType::Standard->value,
            'cost_price' => 10,
            'selling_price' => 20,
        ]);

        $this->inventory()->createMovement([
            'product' => $bad,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 50,
            'unit_cost' => 10,
        ]);

        $sale = $this->sell([
            ['product_id' => $good->id, 'quantity' => 3],
            ['product_id' => $bad->id, 'quantity' => 1],
        ]);

        $before = $this->inventory()->getAvailableStock($good);

        try {
            $this->returns()->create($sale, ['reason' => 'One line is impossible'], [
                $sale->items[0]->id => ['quantity' => 3],
                $sale->items[1]->id => ['quantity' => 9],
            ]);
            $this->fail('The return should have been refused.');
        } catch (HttpException) {
            // expected
        }

        $this->assertSame(0, SaleReturn::query()->allBranches()->count(), 'Nothing was written.');
        $this->assertSame($before, $this->inventory()->getAvailableStock($good), 'The good line did not sneak through.');
    }

    public function test_a_return_needs_something_to_return(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Nothing was marked as coming back.');

        $this->returns()->create($sale, ['reason' => 'Nothing at all'], [
            $sale->items->first()->id => ['quantity' => 0],
        ]);
    }

    // ======================================== restocking is decided per line

    public function test_damaged_goods_are_refunded_but_do_not_go_back_on_the_shelf(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(quantity: 100, cost: 40, price: 70);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);
        $this->assertSame(98.0, $this->inventory()->getAvailableStock($product));

        $return = $this->returns()->create($sale, ['reason' => 'Arrived smashed'], [
            $sale->items->first()->id => [
                'quantity' => 2,
                'restock' => false,
                'condition_note' => 'Bottle cracked, leaking',
            ],
        ]);

        $this->assertSame(140.0, (float) $return->total, 'They still get their money.');
        $this->assertSame(140.0, (float) $return->refunded_amount);
        $this->assertSame(98.0, $this->inventory()->getAvailableStock($product), 'Nothing went back.');
        $this->assertSame(0, StockMovement::query()->allBranches()->where('type', StockMovementType::SaleReturn)->count());

        $this->assertSame(0.0, $return->restockedQuantity());
        $this->assertSame(2.0, $return->writtenOffQuantity());
        $this->assertSame('Bottle cracked, leaking', $return->items->first()->condition_note);
    }

    public function test_one_return_can_restock_some_lines_and_write_off_others(): void
    {
        $this->setUpBusiness();

        $sound = $this->stocked(quantity: 50, cost: 40, price: 70);
        $broken = app(ProductService::class)->create([
            'name' => 'Glass Jar',
            'type' => ProductType::Standard->value,
            'cost_price' => 30,
            'selling_price' => 50,
        ]);

        $this->inventory()->createMovement([
            'product' => $broken,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 50,
            'unit_cost' => 30,
        ]);

        $sale = $this->sell([
            ['product_id' => $sound->id, 'quantity' => 2],
            ['product_id' => $broken->id, 'quantity' => 2],
        ]);

        $return = $this->returns()->create($sale, ['reason' => 'One box was dropped'], [
            $sale->items[0]->id => ['quantity' => 2, 'restock' => true],
            $sale->items[1]->id => ['quantity' => 2, 'restock' => false, 'condition_note' => 'Shattered'],
        ]);

        $this->assertSame(50.0, $this->inventory()->getAvailableStock($sound), 'The sound ones went back.');
        $this->assertSame(48.0, $this->inventory()->getAvailableStock($broken), 'The broken ones did not.');

        $this->assertSame(2.0, $return->restockedQuantity());
        $this->assertSame(2.0, $return->writtenOffQuantity());
        $this->assertSame(240.0, (float) $return->total, 'Both lines are refunded regardless.');
    }

    // ============================================== where the money goes back

    public function test_a_walk_in_is_handed_the_money_and_an_account_customer_is_credited(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        // ---- walk-in -------------------------------------------------------
        $walkIn = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $refunded = $this->returns()->create($walkIn, ['reason' => 'Walk-in'], [
            $walkIn->items->first()->id => ['quantity' => 1],
        ]);

        $this->assertSame(70.0, (float) $refunded->refunded_amount);
        $this->assertSame(0.0, (float) $refunded->credited_amount);
        $this->assertSame('cash', $refunded->refund_method);

        // ---- account customer ----------------------------------------------
        $customer = app(CustomerService::class)->create(['name' => 'Account Customer', 'credit_limit' => 10000]);

        $onAccount = $this->sell(
            [['product_id' => $product->id, 'quantity' => 2]],
            ['customer_id' => $customer->id],
            [],
        );

        $this->assertSame(140.0, (float) $customer->fresh()->balance, 'They owe for the sale.');

        $credited = $this->returns()->create($onAccount, ['reason' => 'On account'], [
            $onAccount->items->first()->id => ['quantity' => 2],
        ]);

        $this->assertSame(0.0, (float) $credited->refunded_amount);
        $this->assertSame(140.0, (float) $credited->credited_amount);
        $this->assertNull($credited->refund_method, 'Nothing was handed over, so no method is recorded.');
        $this->assertSame(0.0, (float) $customer->fresh()->balance, 'The credit cleared what they owed.');
    }

    public function test_a_walk_in_cannot_be_credited(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('no account to credit');

        $this->returns()->create($sale, ['reason' => 'Nowhere to put it', 'credit_amount' => 70], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);
    }

    public function test_the_refund_and_the_credit_have_to_add_up(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $customer = app(CustomerService::class)->create(['name' => 'Split Account', 'credit_limit' => 10000]);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['customer_id' => $customer->id]);

        // 140 is owed back; 40 + 40 is not 140.
        try {
            $this->returns()->create($sale, [
                'reason' => 'Short',
                'refund_amount' => 40,
                'credit_amount' => 40,
            ], [$sale->items->first()->id => ['quantity' => 2]]);
            $this->fail('A settlement that does not add up should be refused.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('but the return is worth', $e->getMessage());
        }

        // …but a split that does add up is fine.
        $return = $this->returns()->create($sale, [
            'reason' => 'Half and half',
            'refund_amount' => 40,
            'credit_amount' => 100,
        ], [$sale->items->first()->id => ['quantity' => 2]]);

        $this->assertSame(40.0, (float) $return->refunded_amount);
        $this->assertSame(100.0, (float) $return->credited_amount);
    }

    public function test_giving_only_one_figure_sends_the_rest_the_other_way(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $customer = app(CustomerService::class)->create(['name' => 'Partial Cash', 'credit_limit' => 10000]);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['customer_id' => $customer->id]);

        // "Hand them 40" — the remaining 100 has to go somewhere, so it is
        // credited rather than quietly lost.
        $return = $this->returns()->create($sale, ['reason' => 'Some cash back', 'refund_amount' => 40], [
            $sale->items->first()->id => ['quantity' => 2],
        ]);

        $this->assertSame(40.0, (float) $return->refunded_amount);
        $this->assertSame(100.0, (float) $return->credited_amount);
        $this->assertSame(140.0, round((float) $return->refunded_amount + (float) $return->credited_amount, 2));
    }

    public function test_returning_part_of_a_discounted_line_gives_back_the_discounted_price(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(quantity: 100, cost: 40, price: 100);

        // 4 × 100 = 400, less 100 discount = 300. One unit is worth 75, not 100.
        $sale = $this->sell([[
            'product_id' => $product->id,
            'quantity' => 4,
            'discount_amount' => 100,
        ]]);

        $this->assertSame(300.0, (float) $sale->total);

        $return = $this->returns()->create($sale, ['reason' => 'Two back'], [
            $sale->items->first()->id => ['quantity' => 2],
        ]);

        $this->assertSame(150.0, (float) $return->total, 'Half of the discounted line, not half of the list price.');
    }

    public function test_a_cash_refund_comes_out_of_the_open_till(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $session = app(CashSessionService::class)->open([
            'branch_id' => $this->branch->id,
            'opening_float' => 500,
        ]);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->returns()->create($sale, ['reason' => 'Cash back', 'refund_method' => 'cash'], [
            $sale->items->first()->id => ['quantity' => 2],
        ]);

        $session = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame(140.0, (float) $session->cash_refunds, 'The drawer is 140 lighter.');
        $this->assertSame($session->id, SaleReturn::query()->allBranches()->firstOrFail()->cash_session_id);
    }

    public function test_a_card_refund_does_not_touch_the_drawer(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $session = app(CashSessionService::class)->open([
            'branch_id' => $this->branch->id,
            'opening_float' => 500,
        ]);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]], [], [
            ['method' => 'card', 'amount' => 70],
        ]);

        $this->returns()->create($sale, ['reason' => 'Back on the card', 'refund_method' => 'card'], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);

        $this->assertSame(0.0, (float) CashSession::query()->allBranches()->findOrFail($session->id)->cash_refunds);
    }

    // ==================================================== the profit reverses

    public function test_the_profit_reverses_at_the_cost_that_applied_when_it_sold(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(quantity: 10, cost: 40, price: 100);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);
        $this->assertSame(40.0, (float) $sale->items->first()->unit_cost);

        // A later delivery at a much higher price re-weights the shelf.
        $this->inventory()->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 100,
            'unit_cost' => 90,
        ]);

        $return = $this->returns()->create($sale, ['reason' => 'Cost check'], [
            $sale->items->first()->id => ['quantity' => 2],
        ]);

        $this->assertSame(80.0, (float) $return->cost_total, '2 × 40, the cost when it sold — not today.');
        $this->assertSame(120.0, $return->profitReversed(), '200 taken, 80 cost.');
    }

    // ==================================== returning and voiding do not overlap

    public function test_a_partly_returned_sale_cannot_be_voided(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 3]]);
        $this->assertTrue($sale->canBeVoided());

        $this->returns()->create($sale, ['reason' => 'One back'], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);

        $sale->refresh();
        $this->assertTrue($sale->hasReturns());
        $this->assertFalse($sale->canBeVoided(), 'Voiding now would put the goods back twice.');

        $this->actingAs($this->owner)
            ->post(route('app.sales.void', $sale), ['reason' => 'Trying anyway'])
            ->assertStatus(422);

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    public function test_only_a_completed_sale_can_be_returned_against(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);
        app(SaleService::class)->void($sale, 'Rung up twice');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only a completed sale can be returned against.');

        $this->returns()->create($sale->fresh(), ['reason' => 'Too late'], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);
    }

    public function test_a_return_needs_a_reason(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Say why the goods are coming back.');

        $this->returns()->create($sale, ['reason' => '  '], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);
    }

    // ================================================== through the interface

    public function test_the_form_posts_a_return_and_lands_on_it(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 3]]);

        $this->actingAs($this->owner)
            ->get(route('app.returns.create', $sale))
            ->assertOk()
            ->assertSee('What is coming back')
            ->assertSee('Fit to sell');

        $response = $this->actingAs($this->owner)->post(route('app.returns.store', $sale), [
            'reason' => 'Wrong colour',
            'lines' => [
                $sale->items->first()->id => ['quantity' => 2, 'restock' => '1'],
            ],
        ]);

        $return = SaleReturn::query()->allBranches()->firstOrFail();

        $response->assertRedirect(route('app.returns.show', $return));

        $this->assertSame(140.0, (float) $return->total);
        $this->assertSame(99.0, $this->inventory()->getAvailableStock($product));

        $this->actingAs($this->owner)
            ->get(route('app.returns.show', $return))
            ->assertOk()
            ->assertSee($return->reference)
            ->assertSee('Back on the shelf');
    }

    public function test_an_unticked_line_is_written_off_when_posted_from_the_form(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        // The browser sends the hidden 0 and no checkbox when it is unticked.
        $this->actingAs($this->owner)->post(route('app.returns.store', $sale), [
            'reason' => 'Damaged',
            'lines' => [
                $sale->items->first()->id => ['quantity' => 2, 'restock' => '0', 'condition_note' => 'Torn'],
            ],
        ])->assertRedirect();

        $return = SaleReturn::query()->allBranches()->with('items')->firstOrFail();

        $this->assertFalse($return->items->first()->restock);
        $this->assertSame(98.0, $this->inventory()->getAvailableStock($product), 'Written off, not restocked.');
    }

    public function test_the_returns_book_totals_what_it_lists(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $customer = app(CustomerService::class)->create(['name' => 'Ledger Customer', 'credit_limit' => 10000]);

        $walkIn = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);
        $this->returns()->create($walkIn, ['reason' => 'Refunded'], [
            $walkIn->items->first()->id => ['quantity' => 1],
        ]);

        $account = $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['customer_id' => $customer->id]);
        $this->returns()->create($account, ['reason' => 'Credited'], [
            $account->items->first()->id => ['quantity' => 2],
        ]);

        $this->actingAs($this->owner)
            ->get(route('app.returns.index'))
            ->assertOk()
            ->assertSee('Refunded')
            ->assertSee('Credited')
            ->assertSee('210.00')   // value returned
            ->assertSee('70.00')    // handed back
            ->assertSee('140.00');  // credited
    }

    public function test_the_book_can_be_searched_by_the_invoice_it_reverses(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();

        $kept = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);
        $wanted = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $keptReturn = $this->returns()->create($kept, ['reason' => 'Not this one'], [
            $kept->items->first()->id => ['quantity' => 1],
        ]);
        $wantedReturn = $this->returns()->create($wanted, ['reason' => 'This one'], [
            $wanted->items->first()->id => ['quantity' => 1],
        ]);

        $this->actingAs($this->owner)
            ->get(route('app.returns.index', ['search' => $wanted->invoice_no]))
            ->assertOk()
            ->assertSee($wantedReturn->reference)
            ->assertDontSee($keptReturn->reference);
    }

    // ============================================== who is allowed to do this

    public function test_refunding_is_its_own_permission(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        // Selling all day long does not grant the authority to hand money back.
        $cashier = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::POS_OPERATE]);

        // Refused in place, not sent to billing: this is their manager's
        // decision, not their plan's.
        $this->actingAs($cashier)
            ->get(route('app.returns.create', $sale))
            ->assertRedirect()
            ->assertSessionHas('permission_denied');

        $this->actingAs($cashier)
            ->post(route('app.returns.store', $sale), [
                'reason' => 'Not allowed',
                'lines' => [$sale->items->first()->id => ['quantity' => 1]],
            ])
            ->assertRedirect()
            ->assertSessionHas('permission_denied');

        $this->assertSame(0, SaleReturn::query()->allBranches()->count());

        // A supervisor who has it, does.
        $supervisor = $this->userWith([
            PermissionRegistry::SALES_VIEW,
            PermissionRegistry::SALES_RETURN,
        ]);

        $this->actingAs($supervisor)->get(route('app.returns.create', $sale))->assertOk();
    }

    public function test_a_plan_without_returns_cannot_reach_them(): void
    {
        $this->setUpBusiness([FeatureRegistry::SALES_RETURNS => false]);
        $product = $this->stocked();
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        // A plan problem, so the owner IS sent to billing — the opposite of a
        // permission refusal.
        $this->actingAs($this->owner)
            ->get(route('app.returns.index'))
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('feature_unavailable');

        $this->actingAs($this->owner)
            ->get(route('app.returns.create', $sale))
            ->assertRedirect(route('app.billing.index'));

        // And the service refuses even if something reaches it directly.
        $this->expectException(FeatureUnavailableException::class);
        $this->returns()->create($sale, ['reason' => 'Not on this plan'], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);
    }

    public function test_another_shops_return_does_not_exist(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $mine = $this->returns()->create($sale, ['reason' => 'Mine'], [
            $sale->items->first()->id => ['quantity' => 1],
        ]);

        // A different shop entirely, with its own owner.
        //
        // ⚠️ The tenant stamp has to come off first. BelongsToTenant forces
        // business_id to whatever is in TenantContext on every insert — that is
        // the point of it — so building the second shop while the first is
        // still active would quietly plant its owner in shop one and the test
        // would pass for the wrong reason.
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

        $this->assertSame($other->id, $otherOwner->fresh()->business_id);

        $this->actingAs($otherOwner)
            ->get(route('app.returns.show', $mine))
            ->assertNotFound();
    }

    // ================================================ the ledger tells the same story

    public function test_the_customer_statement_shows_the_return_against_the_sale(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked();
        $customer = app(CustomerService::class)->create(['name' => 'Statement Customer', 'credit_limit' => 10000]);

        $sale = $this->sell([['product_id' => $product->id, 'quantity' => 4]], ['customer_id' => $customer->id], []);

        $return = $this->returns()->create($sale, ['reason' => 'Two of four'], [
            $sale->items->first()->id => ['quantity' => 2],
        ]);

        $summary = app(CustomerLedgerService::class)->summary($customer->fresh());

        $this->assertSame(280.0, $summary['purchased']);
        $this->assertSame(140.0, $summary['returned']);
        $this->assertSame(140.0, $summary['balance'], 'They still owe for what they kept.');

        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => $return->getMorphClass(),
            'reference_id' => $return->id,
            'reference_no' => $return->reference,
        ]);
    }
}
