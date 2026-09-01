<?php

namespace Tests\Feature\Parties;

use App\Enums\LedgerEntryType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use App\Services\OrganizationProvisioner;
use App\Services\SupplierLedgerService;
use App\Services\SupplierService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Customer and supplier accounts (#38–#42, #137, #183).
 *
 * What these tests exist to protect:
 *   1. ONE ARITHMETIC, TWO HEADINGS. Debit raises what an account owes, credit
 *      lowers it — for both parties. Only the word for it differs.
 *   2. THE LEDGER IS THE TRUTH. `balance` is a cache maintained in the same
 *      transaction, and recalculate() proves the two agree.
 *   3. CREDIT LIMITS ARE ENFORCED IN THE SERVICE (#40), so every route to a
 *      credit sale meets the same gate.
 *   4. ACCOUNTS ARE BUSINESS-WIDE (#137), never per branch.
 */
class PartyLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Ledger Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    /** @param  array<string, bool>  $features */
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

    protected function customerLedger(): CustomerLedgerService
    {
        return app(CustomerLedgerService::class);
    }

    protected function supplierLedger(): SupplierLedgerService
    {
        return app(SupplierLedgerService::class);
    }

    protected function customer(array $overrides = []): Customer
    {
        return app(CustomerService::class)->create(array_merge([
            'name' => 'Ayesha Khan',
            'phone' => '0300 1234567',
        ], $overrides));
    }

    protected function supplier(array $overrides = []): Supplier
    {
        return app(SupplierService::class)->create(array_merge([
            'name' => 'Acme Wholesale',
        ], $overrides));
    }

    // ================================================= the customer side

    public function test_a_new_customer_starts_settled_and_cash_only(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->assertSame(0.0, (float) $customer->balance);
        $this->assertTrue($customer->isSettled());
        $this->assertSame(0.0, $customer->creditLimit(), 'Cash only is the default — credit should be a decision.');
        $this->assertNotEmpty($customer->code, 'A code is allocated when none is supplied.');
    }

    public function test_a_credit_sale_debits_and_a_payment_credits(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        $sale = $this->customerLedger()->chargeSale($customer, 2500);

        $this->assertSame(2500.0, (float) $sale->debit);
        $this->assertSame(0.0, (float) $sale->credit);
        $this->assertSame(2500.0, (float) $sale->balance_after);
        $this->assertSame(2500.0, $customer->fresh()->owesUs());

        $payment = $this->customerLedger()->recordPayment($customer, 1000, ['payment_method' => 'cash']);

        $this->assertSame(0.0, (float) $payment->debit);
        $this->assertSame(1000.0, (float) $payment->credit);
        $this->assertSame(1500.0, (float) $payment->balance_after);
        $this->assertSame(1500.0, $customer->fresh()->owesUs());
    }

    public function test_overpaying_leaves_the_account_in_credit(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        $this->customerLedger()->chargeSale($customer, 500);
        $this->customerLedger()->recordPayment($customer, 800);

        $customer->refresh();

        $this->assertSame(-300.0, (float) $customer->balance);
        $this->assertSame(300.0, $customer->inCredit(), 'A deposit is a real thing; it must not be clipped to zero.');
        $this->assertSame(0.0, $customer->owesUs());
        $this->assertSame('in_credit', $customer->balanceDirection());
    }

    public function test_a_return_reduces_what_they_owe(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        $this->customerLedger()->chargeSale($customer, 1000);
        $this->customerLedger()->recordReturn($customer, 250);

        $this->assertSame(750.0, $customer->fresh()->owesUs());
    }

    // ------------------------------------------------------ credit limits #40

    public function test_a_cash_only_customer_cannot_buy_on_account(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        try {
            $this->customerLedger()->chargeSale($customer, 100);
            $this->fail('A cash-only account was allowed to take credit.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('cash-only', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $customer->fresh()->balance);
    }

    public function test_a_sale_past_the_credit_limit_is_refused(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 1000]);

        $this->customerLedger()->chargeSale($customer, 800);

        $this->assertSame(200.0, $customer->fresh()->availableCredit());

        try {
            $this->customerLedger()->chargeSale($customer->fresh(), 500);
            $this->fail('A sale was allowed past the credit limit.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }

        $this->assertSame(800.0, $customer->fresh()->owesUs(), 'The refusal must not be half-applied.');
    }

    public function test_an_unlimited_account_has_no_ceiling(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => null, 'unlimited_credit' => true]);

        $this->assertTrue($customer->hasUnlimitedCredit());
        $this->assertNull($customer->availableCredit());

        $this->customerLedger()->chargeSale($customer, 999999);

        $this->assertSame(999999.0, $customer->fresh()->owesUs());
    }

    public function test_credit_sales_are_a_plan_capability_too(): void
    {
        $this->setUpBusiness([FeatureRegistry::SALES_CREDIT_SALES => false]);
        $customer = $this->customer(['credit_limit' => 50000]);

        // The customer's own limit says yes; the plan says no, and the plan is
        // checked in the service so no route can slip past it (#40).
        $this->assertTrue($customer->canTakeCredit(100));

        $this->expectException(FeatureUnavailableException::class);

        $this->customerLedger()->chargeSale($customer, 100);
    }

    public function test_a_blocked_customer_cannot_buy_on_account_whatever_their_limit(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 50000]);

        app(CustomerService::class)->setActive($customer, false, 'Repeated late payment');

        $this->assertTrue($customer->fresh()->isBlocked());
        $this->assertFalse($customer->fresh()->canTakeCredit(10));

        $this->expectException(HttpException::class);

        $this->customerLedger()->chargeSale($customer->fresh(), 100);
    }

    public function test_blocking_keeps_the_balance_and_the_statement(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        $this->customerLedger()->chargeSale($customer, 1500);
        app(CustomerService::class)->setActive($customer, false, 'Dispute');

        $customer->refresh();

        $this->assertSame(1500.0, $customer->owesUs(), 'Blocking is not forgiving the debt.');
        $this->assertSame(1, $customer->ledgerEntries()->count());
        $this->assertSame('Dispute', $customer->blocked_reason);

        // And a payment can still be taken — a blocked customer settling up is
        // exactly what the shop wants to encourage.
        $this->customerLedger()->recordPayment($customer, 1500);
        $this->assertTrue($customer->fresh()->isSettled());
    }

    // ================================================= the supplier side

    public function test_a_purchase_debits_the_supplier_account_and_a_payment_credits_it(): void
    {
        $this->setUpBusiness();
        $supplier = $this->supplier();

        $purchase = $this->supplierLedger()->recordPurchase($supplier, 40000);

        $this->assertSame(40000.0, (float) $purchase->debit);
        $this->assertSame(40000.0, $supplier->fresh()->weOwe(), 'Positive means the business owes them.');

        $payment = $this->supplierLedger()->recordPayment($supplier, 15000, ['payment_method' => 'bank_transfer']);

        $this->assertSame(15000.0, (float) $payment->credit);
        $this->assertSame(25000.0, $supplier->fresh()->weOwe());
    }

    public function test_paying_a_supplier_more_than_owed_becomes_an_advance(): void
    {
        $this->setUpBusiness();
        $supplier = $this->supplier();

        $this->supplierLedger()->recordPurchase($supplier, 5000);
        $this->supplierLedger()->recordPayment($supplier, 7000);

        $supplier->refresh();

        $this->assertSame(0.0, $supplier->weOwe());
        $this->assertSame(2000.0, $supplier->theyOweUs());
        $this->assertSame('in_credit', $supplier->balanceDirection());
    }

    public function test_a_blocked_supplier_cannot_be_purchased_from(): void
    {
        $this->setUpBusiness();
        $supplier = $this->supplier();

        app(SupplierService::class)->setActive($supplier, false, 'Quality dispute');

        $this->expectException(HttpException::class);

        $this->supplierLedger()->recordPurchase($supplier->fresh(), 100);
    }

    // ============================================ the shared arithmetic

    public function test_an_entry_type_from_the_wrong_side_is_refused(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 10000]);

        // A purchase belongs on a supplier's account. Posting it to a customer
        // would balance perfectly and mean nothing.
        try {
            $this->customerLedger()->post($customer, [
                'type' => LedgerEntryType::Purchase,
                'amount' => 100,
            ]);
            $this->fail('A supplier entry was accepted on a customer account.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('cannot carry', $e->getMessage());
        }
    }

    public function test_posting_a_customer_entry_through_the_supplier_service_is_refused(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->expectException(HttpException::class);

        $this->supplierLedger()->post($customer, [
            'type' => LedgerEntryType::Purchase,
            'amount' => 100,
        ]);
    }

    public function test_an_adjustment_goes_either_way_and_is_audited(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $customer = $this->customer(['credit_limit' => 10000]);
        $this->customerLedger()->chargeSale($customer, 1000);

        $up = $this->customerLedger()->adjust($customer, 250, 'Delivery charge missed off');
        $down = $this->customerLedger()->adjust($customer, -50, 'Goodwill rounding');

        $this->assertSame(250.0, (float) $up->debit);
        $this->assertSame(50.0, (float) $down->credit);
        $this->assertSame(1200.0, $customer->fresh()->owesUs());

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $this->business->id,
            'event' => 'ledger.adjusted',
        ]);
    }

    public function test_a_zero_entry_is_refused(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer();

        $this->expectException(HttpException::class);

        $this->customerLedger()->adjust($customer, 0, 'Nothing at all');
    }

    public function test_an_opening_balance_is_recorded_once(): void
    {
        $this->setUpBusiness();

        $customer = $this->customer([
            'opening_balance' => 3500,
            'opening_balance_date' => now()->subMonth()->toDateString(),
        ]);

        $this->assertSame(3500.0, $customer->fresh()->owesUs());

        $second = $this->customerLedger()->recordOpeningBalance($customer->fresh(), 999);

        $this->assertNull($second, 'A second opening balance is a correction, not an opening.');
        $this->assertSame(3500.0, $customer->fresh()->owesUs());
    }

    public function test_a_negative_opening_balance_means_the_shop_owes_them(): void
    {
        $this->setUpBusiness();

        $customer = $this->customer(['opening_balance' => -750]);

        $this->assertSame(750.0, $customer->fresh()->inCredit());
    }

    // ------------------------------------------------ statement & recalculate

    public function test_the_statement_reads_oldest_first_with_a_running_balance(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 20000]);

        $this->customerLedger()->chargeSale($customer, 1000);
        $this->customerLedger()->recordPayment($customer, 400);
        $this->customerLedger()->chargeSale($customer->fresh(), 250);

        $balances = $this->customerLedger()->statement($customer)->pluck('balance_after')
            ->map(fn ($b) => (float) $b)->all();

        $this->assertSame([1000.0, 600.0, 850.0], $balances);
    }

    public function test_a_filtered_statement_carries_the_opening_figure_forward(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 20000]);

        // Last month.
        $this->customerLedger()->post($customer, [
            'type' => LedgerEntryType::Sale, 'amount' => 5000,
            'entry_date' => now()->subMonth()->toDateString(),
        ]);

        // This month.
        $this->customerLedger()->post($customer->fresh(), [
            'type' => LedgerEntryType::PaymentReceived, 'amount' => 2000,
            'entry_date' => now()->toDateString(),
        ]);

        $totals = $this->customerLedger()->totals($customer, now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(5000.0, $totals['opening'], 'A window that starts at zero would not add up.');
        $this->assertSame(2000.0, $totals['credit']);
        $this->assertSame(3000.0, $totals['closing']);
    }

    public function test_recalculate_rebuilds_the_balance_from_the_ledger(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 20000]);

        $this->customerLedger()->chargeSale($customer, 1200);
        $this->customerLedger()->recordPayment($customer, 200);

        // Corrupt the cache behind the service's back.
        Customer::query()->whereKey($customer->id)->update(['balance' => 99999]);

        $result = $this->customerLedger()->recalculate($customer->fresh());

        $this->assertTrue($result['drifted']);
        $this->assertSame(1000.0, $result['after']);
        $this->assertSame(1000.0, $customer->fresh()->owesUs());
    }

    public function test_a_healthy_account_reports_no_drift(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 20000]);

        $this->customerLedger()->chargeSale($customer, 640);

        $this->assertFalse($this->customerLedger()->recalculate($customer->fresh())['drifted']);
    }

    public function test_the_profile_summary_foots_to_the_statement(): void
    {
        $this->setUpBusiness();
        $customer = $this->customer(['credit_limit' => 20000]);

        $this->customerLedger()->chargeSale($customer, 3000);
        $this->customerLedger()->recordReturn($customer->fresh(), 500);
        $this->customerLedger()->recordPayment($customer->fresh(), 1000);

        $summary = $this->customerLedger()->summary($customer->fresh());

        $this->assertSame(3000.0, $summary['purchased']);
        $this->assertSame(500.0, $summary['returned']);
        $this->assertSame(1000.0, $summary['paid']);
        $this->assertSame(1500.0, $summary['balance'], 'The headline figure must match the statement below it.');
    }

    // ------------------------------------------------------ tenancy #137

    public function test_accounts_are_business_wide_not_branch_scoped(): void
    {
        $this->setUpBusiness();

        $customer = $this->customer(['credit_limit' => 10000]);
        $mainBranch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $second = app(BranchService::class)->create(['name' => 'Retail Park']);

        // Ran up at one shop…
        $this->customerLedger()->post($customer, [
            'type' => LedgerEntryType::Sale, 'amount' => 900, 'branch_id' => $mainBranch->id,
        ]);

        // …settled at another. This is the whole point of #137.
        $this->customerLedger()->recordPayment($customer->fresh(), 900, ['branch_id' => $second->id]);

        $this->assertTrue($customer->fresh()->isSettled());
        $this->assertSame(2, $customer->ledgerEntries()->count());

        // A cashier tied to one branch still sees the whole account.
        $cashier = User::factory()->for($this->business)->create(['branch_id' => $second->id]);
        app(BranchContext::class)->forUser($cashier);

        $this->assertSame(2, LedgerEntry::query()->forParty($customer)->count());
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_another_businesss_customer_is_out_of_reach(): void
    {
        $this->setUpBusiness();

        $stranger = app(TenantContext::class)->runFor(
            Business::factory()->create(),
            fn () => Customer::factory()->create(),
        );

        $this->expectException(HttpException::class);

        $this->customerLedger()->recordPayment($stranger, 100);
    }
}
