<?php

namespace Tests\Feature\Parties;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use App\Services\OrganizationProvisioner;
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
 * Who may do what to customer and supplier accounts (#38–#42, #52).
 *
 * The split these tests defend: LOOKING someone up, EDITING who they are, and
 * MOVING WHAT THEY OWE are three different authorities. A shop assistant who can
 * find a customer's phone number must not thereby be able to write off their
 * debt.
 */
class PartyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Party Access Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    /**
     * @param  array<string, bool>  $features
     * @param  array<string, int|null>  $limits
     */
    protected function setUpBusiness(array $features = [], array $limits = []): void
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        $limits = $limits + [
            LimitRegistry::CUSTOMERS => 100,
            LimitRegistry::SUPPLIERS => 100,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ];

        foreach ($limits as $code => $value) {
            $plan->limits()->attach(Limit::query()->where('code', $code)->firstOrFail()->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);
    }

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions): User
    {
        $role = Role::factory()->for($this->business)->withPermissions($permissions)->create();

        return User::factory()->for($this->business)->create(['role_id' => $role->id]);
    }

    protected function customer(array $overrides = []): Customer
    {
        return app(CustomerService::class)->create(array_merge(['name' => 'Ayesha Khan'], $overrides));
    }

    protected function supplier(array $overrides = []): Supplier
    {
        return app(SupplierService::class)->create(array_merge(['name' => 'Acme Wholesale'], $overrides));
    }

    // ================================================== customers over HTTP

    public function test_the_owner_can_work_through_the_whole_customer_flow(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->get(route('app.customers.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.customers.store'), [
                'name' => 'Bilal Traders',
                'phone' => '0300 9876543',
                'credit_limit' => 25000,
                'opening_balance' => 5000,
                'opening_balance_date' => now()->subWeek()->toDateString(),
                'is_active' => '1',
            ])
            ->assertRedirect();

        $customer = Customer::query()->where('name', 'Bilal Traders')->firstOrFail();

        $this->assertSame(5000.0, $customer->owesUs(), 'The opening balance is posted through the ledger.');
        $this->assertSame(20000.0, $customer->availableCredit());

        $this->actingAs($this->owner)
            ->get(route('app.customers.show', $customer))
            ->assertOk()
            ->assertSee('Bilal Traders')
            ->assertSee('Opening balance');

        $this->actingAs($this->owner)
            ->post(route('app.customers.payments', $customer), [
                'amount' => 2000,
                'payment_method' => 'cash',
                'entry_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(3000.0, $customer->fresh()->owesUs());
    }

    public function test_looking_someone_up_does_not_let_you_move_their_balance(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);
        app(CustomerLedgerService::class)->chargeSale($customer, 1000);

        $viewer = $this->userWith([PermissionRegistry::CUSTOMERS_VIEW]);

        // Reading is fine…
        $this->actingAs($viewer)->get(route('app.customers.index'))->assertOk();
        $this->actingAs($viewer)->get(route('app.customers.show', $customer))->assertOk();

        // …editing is not…
        $this->actingAs($viewer)
            ->putJson(route('app.customers.update', $customer), ['name' => 'Renamed'])
            ->assertStatus(403);

        // …and neither is writing off what they owe.
        $this->actingAs($viewer)
            ->postJson(route('app.customers.adjustments', $customer), ['amount' => -1000, 'reason' => 'Sneaky'])
            ->assertStatus(403);

        $this->assertSame(1000.0, $customer->fresh()->owesUs());
        $this->assertSame('Ayesha Khan', $customer->fresh()->name);
    }

    public function test_managing_a_customer_does_not_include_moving_money(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        $manager = $this->userWith([
            PermissionRegistry::CUSTOMERS_VIEW,
            PermissionRegistry::CUSTOMERS_MANAGE,
        ]);

        // They can change the record…
        $this->actingAs($manager)
            ->put(route('app.customers.update', $customer), [
                'name' => 'Ayesha Khan & Sons',
                'credit_limit' => 15000,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('Ayesha Khan & Sons', $customer->fresh()->name);

        // …but taking a payment is a separate authority (#52).
        $this->actingAs($manager)
            ->postJson(route('app.customers.payments', $customer), ['amount' => 500])
            ->assertStatus(403);
    }

    public function test_blocking_a_customer_from_the_screen_records_the_reason(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->actingAs($this->owner)
            ->post(route('app.customers.toggle', $customer), ['blocked_reason' => 'Cheque bounced twice'])
            ->assertRedirect();

        $customer->refresh();

        $this->assertFalse($customer->is_active);
        $this->assertSame('Cheque bounced twice', $customer->blocked_reason);

        // Unblocking clears the reason rather than leaving a stale one behind.
        $this->actingAs($this->owner)->post(route('app.customers.toggle', $customer))->assertRedirect();

        $this->assertTrue($customer->fresh()->is_active);
        $this->assertNull($customer->fresh()->blocked_reason);
    }

    public function test_a_customer_with_a_statement_cannot_be_deleted(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 5000]);
        app(CustomerLedgerService::class)->chargeSale($customer, 100);

        $this->actingAs($this->owner)
            ->delete(route('app.customers.destroy', $customer))
            ->assertRedirect();

        $this->assertNotNull($customer->fresh(), 'Deleting them would take their statement with them.');

        // One with no history can go.
        $fresh = $this->customer(['name' => 'Never Traded']);

        $this->actingAs($this->owner)
            ->delete(route('app.customers.destroy', $fresh))
            ->assertRedirect(route('app.customers.index'));

        $this->assertNull(Customer::query()->find($fresh->id));
    }

    public function test_the_customer_quota_is_enforced(): void
    {
        $this->setUpBusiness(limits: [LimitRegistry::CUSTOMERS => 1]);

        $this->customer();

        // A form post is sent back to the form with the plan's own message,
        // rather than an error page — see LimitExceededException::render().
        $this->actingAs($this->owner)
            ->post(route('app.customers.store'), ['name' => 'One Too Many', 'is_active' => '1'])
            ->assertRedirect();

        // An API caller gets the quota detail as JSON instead (403, the same
        // status every other plan refusal uses).
        $this->actingAs($this->owner)
            ->postJson(route('app.customers.store'), ['name' => 'One Too Many', 'is_active' => '1'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'limit_exceeded');

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_customers_need_the_plan_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::CUSTOMERS_MANAGEMENT => false]);

        $this->actingAs($this->owner)->getJson(route('app.customers.index'))->assertStatus(403);
    }

    public function test_the_customer_ledger_needs_its_own_feature(): void
    {
        $this->setUpBusiness([FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER => false]);
        $customer = $this->customer();

        // The customer list still works — only the money side is gated.
        $this->actingAs($this->owner)->get(route('app.customers.index'))->assertOk();

        $this->actingAs($this->owner)
            ->postJson(route('app.customers.payments', $customer), ['amount' => 100])
            ->assertStatus(403);
    }

    public function test_another_businesss_customer_returns_not_found(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Customer::factory()->create(),
        );

        $this->actingAs($this->owner)->get(route('app.customers.show', $stranger))->assertNotFound();
        $this->actingAs($this->owner)
            ->postJson(route('app.customers.payments', $stranger), ['amount' => 100])
            ->assertNotFound();
    }

    // ================================================== suppliers over HTTP

    public function test_the_owner_can_work_through_the_whole_supplier_flow(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.suppliers.store'), [
                'name' => 'Metro Cash & Carry',
                'contact_person' => 'Imran',
                'payment_terms_days' => 30,
                'opening_balance' => 12000,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $supplier = Supplier::query()->where('name', 'Metro Cash & Carry')->firstOrFail();

        $this->assertSame(12000.0, $supplier->weOwe());

        $this->actingAs($this->owner)
            ->get(route('app.suppliers.show', $supplier))
            ->assertOk()
            ->assertSee('Metro Cash &amp; Carry', false)
            ->assertSee('30-day terms');

        $this->actingAs($this->owner)
            ->post(route('app.suppliers.payments', $supplier), [
                'amount' => 5000,
                'payment_method' => 'bank_transfer',
            ])
            ->assertRedirect();

        $this->assertSame(7000.0, $supplier->fresh()->weOwe());
    }

    public function test_paying_a_supplier_is_a_separate_authority_from_editing_one(): void
    {
        $this->setUpBusiness();
        $supplier = $this->supplier();

        $manager = $this->userWith([
            PermissionRegistry::SUPPLIERS_VIEW,
            PermissionRegistry::SUPPLIERS_MANAGE,
        ]);

        $this->actingAs($manager)->get(route('app.suppliers.show', $supplier))->assertOk();

        $this->actingAs($manager)
            ->postJson(route('app.suppliers.payments', $supplier), ['amount' => 1000])
            ->assertStatus(403);

        $settler = $this->userWith([
            PermissionRegistry::SUPPLIERS_VIEW,
            PermissionRegistry::SUPPLIERS_LEDGER,
        ]);

        $this->actingAs($settler)
            ->post(route('app.suppliers.payments', $supplier), ['amount' => 1000])
            ->assertRedirect();

        $this->assertSame(1000.0, $supplier->fresh()->theyOweUs(), 'Paying with nothing owed leaves an advance.');
    }

    public function test_suppliers_need_the_purchasing_module_not_the_ledger(): void
    {
        // Without purchasing at all there is nobody to buy from.
        $this->setUpBusiness([FeatureRegistry::PURCHASES_ORDERS => false]);

        $this->actingAs($this->owner)->getJson(route('app.suppliers.index'))->assertStatus(403);
    }

    public function test_a_plan_that_can_order_can_always_reach_its_suppliers(): void
    {
        /*
         | ⚠️ THE BUG THIS PINS. Suppliers used to sit behind
         | `purchases.supplier_ledger`, which is off by default, while
         | `purchases.orders` is ON by default — and a purchase order REQUIRES a
         | supplier_id. So the shipped default plan could raise purchase orders
         | and had no way to create, or even see, a single supplier. The
         | Suppliers menu was simply absent, which is exactly how it was
         | reported: "Suppliers sidebar mein show nahi ho raha".
         |
         | Ordering and knowing who you order from are the same capability.
         */
        $this->setUpBusiness([
            FeatureRegistry::PURCHASES_ORDERS => true,
            FeatureRegistry::PURCHASES_SUPPLIER_LEDGER => false,
        ]);

        $this->actingAs($this->owner)->get(route('app.suppliers.index'))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('app.suppliers.store'), ['name' => 'Reachable Supplier'])
            ->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['name' => 'Reachable Supplier']);
    }

    public function test_the_ledger_itself_still_needs_its_own_feature(): void
    {
        // The list is the module; MONEY on a supplier account is the paid part.
        // Losing that distinction is what caused the bug above.
        $this->setUpBusiness([
            FeatureRegistry::PURCHASES_ORDERS => true,
            FeatureRegistry::PURCHASES_SUPPLIER_LEDGER => false,
        ]);

        $supplier = $this->supplier();

        $this->actingAs($this->owner)
            ->postJson(route('app.suppliers.payments', $supplier), ['amount' => 100])
            ->assertStatus(403);
    }

    // ------------------------------------------------------- validation

    public function test_a_payment_must_be_positive_and_not_future_dated(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->actingAs($this->owner)
            ->post(route('app.customers.payments', $customer), ['amount' => 0])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->owner)
            ->post(route('app.customers.payments', $customer), [
                'amount' => 100,
                'entry_date' => now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('entry_date');

        $this->assertSame(0, $customer->ledgerEntries()->count());
    }

    public function test_an_adjustment_demands_a_reason(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->actingAs($this->owner)
            ->post(route('app.customers.adjustments', $customer), ['amount' => -500])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $customer->ledgerEntries()->count());
    }

    public function test_two_customers_cannot_share_a_code(): void
    {
        $this->setUpBusiness();
        $this->customer(['code' => 'VIP-1']);

        $this->actingAs($this->owner)
            ->post(route('app.customers.store'), ['name' => 'Someone Else', 'code' => 'VIP-1', 'is_active' => '1'])
            ->assertSessionHasErrors('code');
    }
}
