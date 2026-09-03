<?php

namespace Tests\Feature\Inventory;

use App\Enums\ProductType;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brand new product must have a way to be given its first stock.
 *
 * ================= THE DEAD END THIS PINS =================
 * The adjustment form — the only place a shop can type in an opening quantity —
 * lives on a product's stock ledger. Every link to that page came from the
 * Inventory list, and the Inventory list is built from `stocks` rows.
 *
 * A product nobody has counted in has no `stocks` row. So it never appeared in
 * the list, the ledger had no route in, the form could not be reached, and the
 * only remaining way to put stock on a shelf was to raise a purchase order —
 * while the empty state on that very list said stock shows up once something is
 * "purchased, ADJUSTED or counted in".
 *
 * The screen worked perfectly. Nobody could get to it. That is the same shape
 * as the Suppliers menu that was not there.
 */
class OpeningStockReachableTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

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
        $this->owner->refresh();
    }

    protected function freshProduct(): Product
    {
        return app(ProductService::class)->create([
            'name' => 'Never Counted In',
            'type' => ProductType::Standard->value,
            'selling_price' => 250,
        ]);
    }

    public function test_a_product_with_no_movements_is_absent_from_the_inventory_list(): void
    {
        $product = $this->freshProduct();

        // Not a bug in itself — the list is what has MOVED, and that is the
        // honest thing for it to be. It only became a trap because it was also
        // the only door to the ledger.
        $this->assertSame(0, Stock::query()->allBranches()->where('product_id', $product->id)->count());

        $this->actingAs($this->owner)
            ->get(route('app.inventory.index'))
            ->assertOk()
            ->assertDontSee('Never Counted In');
    }

    public function test_the_products_list_links_to_the_stock_ledger(): void
    {
        $product = $this->freshProduct();

        // ⚠️ THE FIX. Without this link there is no route from a new product to
        // the only form that can give it an opening quantity.
        $this->actingAs($this->owner)
            ->get(route('app.products.index'))
            ->assertOk()
            ->assertSee(route('app.inventory.ledger', $product), escape: false);
    }

    public function test_the_ledger_offers_the_adjustment_form_even_with_no_history(): void
    {
        $product = $this->freshProduct();

        // The empty history must not take the form down with it.
        $this->actingAs($this->owner)
            ->get(route('app.inventory.ledger', $product))
            ->assertOk()
            ->assertSee(route('app.inventory.adjust'), escape: false);
    }

    public function test_opening_stock_can_actually_be_entered(): void
    {
        $product = $this->freshProduct();

        $this->actingAs($this->owner)->post(route('app.inventory.adjust'), [
            'product_id' => $product->id,
            'quantity' => 25,
            'reason' => 'Opening count',
        ])->assertRedirect();

        // And now it exists on a shelf, with a movement explaining why.
        $this->assertSame(25.0, (float) Stock::query()->allBranches()
            ->where('product_id', $product->id)->firstOrFail()->quantity);

        $this->actingAs($this->owner)
            ->get(route('app.inventory.index'))
            ->assertSee('Never Counted In');
    }
}
