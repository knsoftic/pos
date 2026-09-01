<?php

namespace Tests\Feature\Performance;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduled housekeeping (#169, #170, #171).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE ONLY DESTRUCTIVE JOB IS SAFE. Expiring a held sale throws away a
 *     basket, not a transaction — nothing was ever posted.
 *  2. THE INTEGRITY CHECK CATCHES DRIFT, and reports before it repairs:
 *     quietly fixing a discrepancy destroys the evidence of what caused it.
 *  3. NOTHING FINANCIAL IS EVER PRUNED (#133, #198).
 */
class ScheduledTasksTest extends TestCase
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

        $this->business = Business::factory()->create(['name' => 'Housekeeping Shop']);
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

    // ================================================== holds expire (#169)

    public function test_a_stale_held_sale_is_discarded_and_a_fresh_one_is_not(): void
    {
        $product = $this->stocked();

        $stale = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 2]]);
        $fresh = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 1]]);

        Sale::query()->allBranches()->whereKey($stale->id)->update([
            'created_at' => now()->subHours((int) config('pos.hold_expiry_hours', 24) + 2),
        ]);

        $stockBefore = app(InventoryService::class)->getAvailableStock($product);

        $this->artisan('pos:expire-holds')->assertSuccessful();

        $this->assertNull(Sale::query()->allBranches()->find($stale->id), 'The stale hold went.');
        $this->assertNotNull(Sale::query()->allBranches()->find($fresh->id), 'The fresh one stayed.');

        // Safe to automate precisely because a hold posted nothing.
        $this->assertSame($stockBefore, app(InventoryService::class)->getAvailableStock($product));
    }

    public function test_the_hold_window_is_the_shops_own_setting(): void
    {
        $product = $this->stocked();
        $held = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 1]]);

        Sale::query()->allBranches()->whereKey($held->id)->update(['created_at' => now()->subHours(30)]);

        // A shop that parks orders for three days keeps them for three days.
        app(SettingsService::class)->put(['pos.hold_expiry_hours' => 72]);

        $this->artisan('pos:expire-holds')->assertSuccessful();
        $this->assertNotNull(Sale::query()->allBranches()->find($held->id));

        app(SettingsService::class)->put(['pos.hold_expiry_hours' => 1]);

        $this->artisan('pos:expire-holds')->assertSuccessful();
        $this->assertNull(Sale::query()->allBranches()->find($held->id));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $product = $this->stocked();
        $held = app(SaleService::class)->hold([], [['product_id' => $product->id, 'quantity' => 1]]);

        Sale::query()->allBranches()->whereKey($held->id)->update(['created_at' => now()->subDays(5)]);

        $this->artisan('pos:expire-holds --dry-run')->assertSuccessful();

        $this->assertNotNull(Sale::query()->allBranches()->find($held->id));
    }

    // ================================================ integrity check (#170)

    public function test_a_clean_shop_reconciles(): void
    {
        $product = $this->stocked();

        app(SaleService::class)->complete([], [['product_id' => $product->id, 'quantity' => 3]], [
            ['method' => 'cash', 'amount' => 1000],
        ]);

        $this->artisan('pos:check-integrity')->assertSuccessful();
    }

    public function test_drift_is_found_reported_and_only_repaired_when_asked(): void
    {
        $this->stocked();

        // Corrupt the CACHE, not the ledger — exactly the bug this exists for.
        $shelf = Stock::query()->allBranches()->firstOrFail();
        DB::table('stocks')->where('id', $shelf->id)->update(['quantity' => 12]);

        // Reports and fails, so a cron that surfaces failures surfaces this.
        $this->artisan('pos:check-integrity')->assertFailed();

        // …and has not touched it: the cause matters more than the symptom.
        $this->assertSame(12.0, (float) Stock::query()->allBranches()->find($shelf->id)->quantity);

        $this->artisan('pos:check-integrity --repair')->assertSuccessful();

        $this->assertSame(100.0, (float) Stock::query()->allBranches()->find($shelf->id)->quantity);
    }

    public function test_a_drifted_customer_balance_is_found_too(): void
    {
        $customer = app(CustomerService::class)->create(['name' => 'Drifter', 'credit_limit' => 10000]);
        $product = $this->stocked();

        app(SaleService::class)->complete(
            ['customer_id' => $customer->id],
            [['product_id' => $product->id, 'quantity' => 2]],
            [],
        );

        DB::table('customers')->where('id', $customer->id)->update(['balance' => 999]);

        $this->artisan('pos:check-integrity')->assertFailed();
        $this->artisan('pos:check-integrity --repair')->assertSuccessful();

        $this->assertSame(200.0, (float) Customer::query()->find($customer->id)->balance);
    }

    // ====================================================== pruning (#170)

    public function test_pruning_takes_the_old_audit_trail_and_nothing_financial(): void
    {
        $product = $this->stocked();

        $sale = app(SaleService::class)->complete([], [['product_id' => $product->id, 'quantity' => 1]], [
            ['method' => 'cash', 'amount' => 1000],
        ]);

        $salesBefore = Sale::query()->allBranches()->count();
        $movementsBefore = StockMovement::query()->allBranches()->count();

        AuditLog::query()->update(['created_at' => now()->subYears(5)]);
        $this->assertGreaterThan(0, AuditLog::query()->count());

        $this->artisan('pos:prune')->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->count(), 'The old trail went.');

        // ⚠️ The line this command must never cross (#133, #198).
        $this->assertSame($salesBefore, Sale::query()->allBranches()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->allBranches()->count());
        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    public function test_pruning_refuses_an_absurd_retention_window(): void
    {
        AuditLog::query()->update(['created_at' => now()->subYears(5)]);
        $before = AuditLog::query()->count();

        // A window shorter than a month is almost certainly a typo, and this
        // command cannot undo itself.
        $this->artisan('pos:prune --days=1')->assertFailed();

        $this->assertSame($before, AuditLog::query()->count());
    }

    // ==================================================== the schedule (#171)

    public function test_every_scheduled_task_is_safe_on_more_than_one_server(): void
    {
        $schedule = app(Schedule::class);

        $this->assertNotEmpty($schedule->events());

        foreach ($schedule->events() as $event) {
            // Without these two, a platform behind two web servers runs every
            // nightly job twice — and the integrity sweep twice over a large
            // tenant list is a self-inflicted outage.
            $this->assertTrue($event->onOneServer, $event->command.' may run on every server at once.');
            $this->assertTrue($event->withoutOverlapping, $event->command.' may overlap with itself.');
        }
    }
}
