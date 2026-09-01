<?php

namespace Tests\Feature\Security;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ImmutableRecordException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Financial records are reversed, never erased (#133, #198).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE RULE IS ABOUT STATUS, NOT ABOUT TABLES. A held sale is a basket and
 *     may be thrown away; a completed one is a document somebody was handed.
 *     Same table, opposite answers — and getting that backwards either breaks
 *     the till or lets an invoice disappear.
 *  2. A MASS DELETE MUST NOT BE A BACK DOOR. `$sale->items()->delete()` skips
 *     model events, which is the ordinary way to delete a relation's rows.
 *  3. THE LEDGERS ARE THE TRUTH. Remove one stock movement or one ledger entry
 *     and every figure after it is a fiction that nobody can explain.
 */
class FinancialIntegrityTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Ledger Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 500]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();
        $this->actingAs($this->owner);
    }

    protected function stocked(): Product
    {
        $product = app(ProductService::class)->create([
            'name' => 'Cola '.fake()->unique()->numerify('###'),
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 100,
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

    protected function completedSale(): Sale
    {
        return app(SaleService::class)->complete([], [
            ['product_id' => $this->stocked()->id, 'quantity' => 2],
        ], [
            ['method' => 'cash', 'amount' => 500],
        ]);
    }

    // ============================================ the rule is about status

    public function test_a_held_sale_may_be_thrown_away_because_it_posted_nothing(): void
    {
        $product = $this->stocked();
        $held = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 2]]);

        $this->assertTrue($held->isDeletableRecord());
        $this->assertTrue(app(SaleService::class)->discardHold($held));
        $this->assertNull(Sale::query()->allBranches()->find($held->id));
    }

    public function test_a_completed_sale_cannot_be_deleted_at_all(): void
    {
        $sale = $this->completedSale();

        $this->assertFalse($sale->isDeletableRecord());

        // ⚠️ Somebody has the paper copy, and a figure somewhere already
        // counted it. The remedy is a void, which leaves the record standing.
        $this->expectException(ImmutableRecordException::class);
        $sale->delete();
    }

    public function test_a_voided_sale_is_not_deletable_either(): void
    {
        $sale = app(SaleService::class)->void($this->completedSale(), 'wrong customer');

        $this->assertSame(SaleStatus::Voided, $sale->status);

        // Voiding is the reversal. Deleting afterwards would erase the evidence
        // that the reversal was ever needed.
        $this->expectException(ImmutableRecordException::class);
        $sale->delete();
    }

    public function test_voiding_reverses_the_postings_and_keeps_the_document(): void
    {
        $sale = $this->completedSale();
        $movementsBefore = StockMovement::query()->allBranches()->count();

        app(SaleService::class)->void($sale, 'customer changed their mind');

        $this->assertNotNull(Sale::query()->allBranches()->find($sale->id), 'The invoice still exists.');

        // Reversed by ADDING an opposite movement, never by removing the first.
        $this->assertGreaterThan($movementsBefore, StockMovement::query()->allBranches()->count());
    }

    // ============================================ the mass-delete back door

    public function test_a_mass_delete_cannot_take_the_lines_of_a_posted_sale(): void
    {
        $sale = $this->completedSale();

        /*
         | ⚠️ THE HOLE THIS EXISTS FOR. `Builder::delete()` compiles one DELETE
         | statement and never loads a model, so model events do not fire. This
         | is not an exotic call — it is the ordinary way to clear a relation.
         */
        $this->expectException(ImmutableRecordException::class);
        $sale->items()->delete();
    }

    public function test_the_lines_survive_the_refused_mass_delete(): void
    {
        $sale = $this->completedSale();
        $before = SaleItem::query()->where('sale_id', $sale->id)->count();

        try {
            SaleItem::query()->where('sale_id', $sale->id)->delete();
        } catch (ImmutableRecordException) {
            // Expected.
        }

        $this->assertSame($before, SaleItem::query()->where('sale_id', $sale->id)->count());
    }

    public function test_a_held_sales_lines_still_go_when_the_hold_is_discarded(): void
    {
        // The guard must not break the one deletion that is correct — the
        // nightly `pos:expire-holds` depends on exactly this path.
        $held = app(SaleService::class)->hold([], [
            ['product_id' => $this->stocked()->id, 'quantity' => 3],
        ]);

        $this->assertSame(1, SaleItem::query()->where('sale_id', $held->id)->count());

        app(SaleService::class)->discardHold($held);

        $this->assertSame(0, SaleItem::query()->where('sale_id', $held->id)->count());
    }

    // =================================================== the ledgers are truth

    public function test_a_stock_movement_can_never_be_deleted(): void
    {
        $this->stocked();
        $movement = StockMovement::query()->allBranches()->firstOrFail();

        // Stock is not a number, it is the sum of these lines. Remove one and
        // every quantity after it becomes a fiction — one that
        // `pos:check-integrity` reports and nobody can explain.
        $this->expectException(ImmutableRecordException::class);
        $movement->delete();
    }

    public function test_a_ledger_entry_can_never_be_deleted(): void
    {
        $customer = app(CustomerService::class)->create(['name' => 'On Account', 'credit_limit' => 10000]);

        app(SaleService::class)->complete(
            ['customer_id' => $customer->id],
            [['product_id' => $this->stocked()->id, 'quantity' => 1]],
            [],
        );

        $entry = LedgerEntry::query()->firstOrFail();

        $this->expectException(ImmutableRecordException::class);
        $entry->delete();
    }

    public function test_a_payment_can_never_be_deleted(): void
    {
        $this->completedSale();

        $this->expectException(ImmutableRecordException::class);
        SalePayment::query()->firstOrFail()->delete();
    }

    public function test_a_cash_session_can_never_be_deleted(): void
    {
        $session = CashSession::factory()->create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        // "Was the till short that day?" is the one question a deleted session
        // makes permanently unanswerable.
        $this->expectException(ImmutableRecordException::class);
        $session->delete();
    }

    // ================================================ nothing forgets the rule

    public function test_a_model_that_joins_the_guard_is_protected_by_default(): void
    {
        // The default answer is NO, so a model that adopts the trait and
        // forgets to say when it may be deleted falls the safe way.
        $movement = new StockMovement;

        $this->assertFalse($movement->isDeletableRecord());
    }

    public function test_an_expense_is_the_one_deliberate_exception(): void
    {
        /*
         | ⚠️ THIS TEST EXISTS TO MAKE AN INCONSISTENCY A DECISION.
         |
         | An expense CAN be deleted, and it is the only thing in the P&L that
         | can. The case for it: an expense is a note the shop wrote about
         | itself, with no counterparty who also has a copy, and deleting one
         | puts the cash back in the drawer and writes an audit row — so it is
         | reversible and traceable in a way a sale never is.
         |
         | The case against, recorded here rather than lost: deleting an expense
         | silently restates a closed month's profit, and nothing stops that
         | happening to a period somebody has already reported on.
         |
         | Left as built (Phase 9) because it works, is tested and is used. If
         | that trade is ever revisited, this test is where the argument is.
         */
        $expense = Expense::factory()->create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue(
            in_array('App\Models\Concerns\ProtectsFinancialRecords', class_uses_recursive($expense), true) === false,
            'Expense is deliberately outside the guard — change this test, not just the model.',
        );

        $expense->delete();

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_no_http_route_offers_to_delete_a_financial_document(): void
    {
        $forbidden = [
            'app.sales.destroy',
            'app.sale-returns.destroy',
            'app.purchase-returns.destroy',
            'app.payments.destroy',
            'app.ledger.destroy',
            'app.cash-sessions.destroy',
            'app.movements.destroy',
        ];

        $names = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->all();

        foreach ($forbidden as $name) {
            $this->assertNotContains($name, $names, "{$name} should not exist: these are voided, not deleted.");
        }
    }
}
