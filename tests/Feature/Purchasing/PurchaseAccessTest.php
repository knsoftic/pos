<?php

namespace Tests\Feature\Purchasing;

use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
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
 * Purchases over HTTP: who may do what (#35–#37, #52).
 *
 * The split defended here: raising an order, editing a draft, calling one off,
 * sending goods back and PAYING THE BILL are five different authorities. Paying
 * rides on `suppliers.ledger` rather than on a purchase permission, because
 * money on a supplier's account is the same authority wherever it is moved from.
 */
class PurchaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Purchasing Access Shop']);
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
            LimitRegistry::SUPPLIERS => 50,
            LimitRegistry::CUSTOMERS => 50,
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
        $this->supplier = app(SupplierService::class)->create(['name' => 'Metro Wholesale']);
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

    protected function product(): Product
    {
        return app(ProductService::class)->create([
            'name' => 'Cola 500ml',
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => 70,
        ]);
    }

    protected function draft(): Purchase
    {
        return app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
        ], [
            ['product_id' => $this->product()->id, 'quantity_ordered' => 10, 'unit_cost' => 50],
        ]);
    }

    // ================================================== the whole flow

    public function test_the_owner_can_run_a_purchase_from_draft_to_paid(): void
    {
        $this->setUpBusiness();
        $product = $this->product();

        $this->actingAs($this->owner)->get(route('app.purchases.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('app.purchases.create'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.purchases.store'), [
                'supplier_id' => $this->supplier->id,
                'branch_id' => $this->branch->id,
                'order_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $product->id, 'quantity_ordered' => 10, 'unit_cost' => 50, 'tax_rate' => 0],
                    // The blank template row the form always renders.
                    ['product_id' => '', 'quantity_ordered' => ''],
                ],
            ])
            ->assertRedirect();

        $purchase = Purchase::query()->firstOrFail();

        $this->assertSame(1, $purchase->items()->count(), 'The empty template row is dropped.');
        $this->assertSame(500.0, (float) $purchase->total);

        $this->actingAs($this->owner)
            ->get(route('app.purchases.show', $purchase))
            ->assertOk()
            ->assertSee($purchase->reference)
            ->assertSee('Metro Wholesale');

        // Send it, then take the delivery in and pay at the door.
        $this->actingAs($this->owner)->post(route('app.purchases.order', $purchase))->assertRedirect();

        $this->actingAs($this->owner)
            ->post(route('app.purchases.receive', $purchase), [
                'received_date' => now()->toDateString(),
                'pay_now' => 500,
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $purchase->refresh();

        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertSame(10.0, app(InventoryService::class)->getAvailableStock($product));
        $this->assertTrue($purchase->isSettled());
        $this->assertTrue($this->supplier->fresh()->isSettled());
    }

    public function test_a_partial_delivery_can_be_received_over_two_visits(): void
    {
        $this->setUpBusiness();
        $purchase = app(PurchaseService::class)->order($this->draft());
        $item = $purchase->items->first();

        $this->actingAs($this->owner)
            ->post(route('app.purchases.receive', $purchase), ['received' => [$item->id => 4]])
            ->assertRedirect();

        $this->assertSame(PurchaseStatus::Partial, $purchase->fresh()->status);

        $this->actingAs($this->owner)
            ->post(route('app.purchases.receive', $purchase->fresh()), ['received' => [$item->id => 6]])
            ->assertRedirect();

        $this->assertSame(PurchaseStatus::Received, $purchase->fresh()->status);
        $this->assertSame(10.0, (float) $item->fresh()->quantity_received);
    }

    // ------------------------------------------------------- the authorities

    public function test_reading_purchases_does_not_let_you_raise_or_receive_one(): void
    {
        $this->setUpBusiness();
        $purchase = app(PurchaseService::class)->order($this->draft());

        $viewer = $this->userWith([PermissionRegistry::PURCHASES_VIEW]);

        $this->actingAs($viewer)->get(route('app.purchases.index'))->assertOk();
        $this->actingAs($viewer)->get(route('app.purchases.show', $purchase))->assertOk();

        $this->actingAs($viewer)->getJson(route('app.purchases.create'))->assertStatus(403);
        $this->actingAs($viewer)->postJson(route('app.purchases.receive', $purchase))->assertStatus(403);

        $this->assertSame(PurchaseStatus::Ordered, $purchase->fresh()->status);
    }

    public function test_raising_a_purchase_does_not_let_you_pay_for_it(): void
    {
        $this->setUpBusiness();

        $buyer = $this->userWith([
            PermissionRegistry::PURCHASES_VIEW,
            PermissionRegistry::PURCHASES_CREATE,
        ]);

        $purchase = app(PurchaseService::class)->receive(app(PurchaseService::class)->order($this->draft()));

        // They can order and receive…
        $this->actingAs($buyer)->get(route('app.purchases.show', $purchase))->assertOk();

        // …but money on a supplier account is its own authority (#52).
        $this->actingAs($buyer)
            ->postJson(route('app.purchases.payments', $purchase), ['amount' => 100])
            ->assertStatus(403);

        $this->assertSame(0.0, (float) $purchase->fresh()->paid_amount);
    }

    public function test_cancelling_needs_the_void_permission_and_a_reason(): void
    {
        $this->setUpBusiness();
        $purchase = app(PurchaseService::class)->order($this->draft());

        $buyer = $this->userWith([
            PermissionRegistry::PURCHASES_VIEW,
            PermissionRegistry::PURCHASES_CREATE,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('app.purchases.cancel', $purchase), ['reason' => 'Changed my mind'])
            ->assertStatus(403);

        // The owner can, but not without saying why.
        $this->actingAs($this->owner)
            ->post(route('app.purchases.cancel', $purchase), [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)
            ->post(route('app.purchases.cancel', $purchase), ['reason' => 'Supplier out of stock'])
            ->assertRedirect();

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->fresh()->status);
    }

    public function test_returning_goods_is_its_own_authority(): void
    {
        $this->setUpBusiness();
        $purchase = app(PurchaseService::class)->receive(app(PurchaseService::class)->order($this->draft()));
        $item = $purchase->items->first();

        $buyer = $this->userWith([
            PermissionRegistry::PURCHASES_VIEW,
            PermissionRegistry::PURCHASES_CREATE,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('app.purchases.returns.store', $purchase), [
                'reason' => 'Damaged', 'quantities' => [$item->id => 2],
            ])
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->get(route('app.purchases.returns.create', $purchase))
            ->assertOk()
            ->assertSee('Send goods back');

        $this->actingAs($this->owner)
            ->post(route('app.purchases.returns.store', $purchase), [
                'reason' => 'Two cases damaged',
                'quantities' => [$item->id => 2],
            ])
            ->assertRedirect(route('app.purchases.show', $purchase));

        $this->assertSame(1, $purchase->fresh()->returns()->count());
        $this->assertSame(400.0, $this->supplier->fresh()->weOwe(), '500 billed, 100 credited back.');
    }

    public function test_a_return_without_a_reason_is_refused(): void
    {
        $this->setUpBusiness();
        $purchase = app(PurchaseService::class)->receive(app(PurchaseService::class)->order($this->draft()));

        $this->actingAs($this->owner)
            ->post(route('app.purchases.returns.store', $purchase), [
                'quantities' => [$purchase->items->first()->id => 1],
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $purchase->fresh()->returns()->count());
    }

    // ------------------------------------------------------- gates & tenancy

    public function test_purchases_need_the_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::PURCHASES_ORDERS => false]);

        $this->actingAs($this->owner)->getJson(route('app.purchases.index'))->assertStatus(403);
    }

    public function test_returns_need_their_own_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::PURCHASES_RETURNS => false]);

        $purchase = app(PurchaseService::class)->receive(app(PurchaseService::class)->order($this->draft()));

        // The purchase itself is still readable; only the return is gated.
        $this->actingAs($this->owner)->get(route('app.purchases.show', $purchase))->assertOk();

        $this->actingAs($this->owner)
            ->getJson(route('app.purchases.returns.create', $purchase))
            ->assertStatus(403);
    }

    public function test_another_businesss_purchase_is_out_of_reach(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Purchase::factory()->create(),
        );

        $this->actingAs($this->owner)->get(route('app.purchases.show', $stranger))->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('app.purchases.order', $stranger))->assertNotFound();
    }

    public function test_an_order_cannot_be_dated_in_the_future(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.purchases.store'), [
                'supplier_id' => $this->supplier->id,
                'branch_id' => $this->branch->id,
                'order_date' => now()->addWeek()->toDateString(),
                'lines' => [['product_id' => $this->product()->id, 'quantity_ordered' => 1, 'unit_cost' => 10]],
            ])
            ->assertSessionHasErrors('order_date');
    }

    public function test_a_purchase_with_no_usable_lines_is_refused(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.purchases.store'), [
                'supplier_id' => $this->supplier->id,
                'branch_id' => $this->branch->id,
                'order_date' => now()->toDateString(),
                'lines' => [['product_id' => '', 'quantity_ordered' => '']],
            ])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, Purchase::query()->count());
    }
}
