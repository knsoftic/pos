<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\OtherIncome;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\ExpenseService;
use App\Services\OrganizationProvisioner;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Expenses and other income (#43, #44).
 *
 * What these tests exist to protect:
 *   1. THE DRAWER STAYS HONEST. Paying the window cleaner out of the till
 *      really does empty it, and an edit moves the drawer by the DIFFERENCE,
 *      not the new figure.
 *   2. A CLOSED SESSION IS HISTORY. An expense edited days later must not
 *      rewrite a cash-up that was already counted and signed off.
 *   3. CATEGORIES ARE THE SHOP'S OWN and are archived, never deleted, once
 *      figures are filed under them.
 *   4. THE RECEIPT IS PART OF THE RECORD — stored, replaced, removed, and never
 *      left orphaned on disk when a write is refused.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Expense Test Shop']);
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

    protected function expenses(): ExpenseService
    {
        return app(ExpenseService::class);
    }

    protected function category(string $name = 'Rent'): ExpenseCategory
    {
        return ExpenseCategory::query()->where('name', $name)->first()
            ?? $this->expenses()->createCategory(['name' => $name]);
    }

    protected function openTill(float $float = 1000): CashSession
    {
        return app(CashSessionService::class)->open([
            'branch_id' => $this->branch->id,
            'opening_float' => $float,
        ]);
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

    // ============================================== what an expense records

    public function test_an_expense_is_filed_under_a_category_and_numbered(): void
    {
        $this->setUpBusiness();
        $category = $this->category('Utilities');

        $first = $this->expenses()->create([
            'expense_category_id' => $category->id,
            'amount' => 4500,
            'payee' => 'K-Electric',
            'bill_no' => 'KE-9931',
        ]);

        $second = $this->expenses()->create([
            'expense_category_id' => $category->id,
            'amount' => 900,
        ]);

        $this->assertSame('EXP-000001', $first->reference);
        $this->assertSame('EXP-000002', $second->reference);
        $this->assertSame(4500.0, (float) $first->amount);
        $this->assertSame($category->id, $first->expense_category_id);
        $this->assertSame($this->branch->id, $first->branch_id, 'An expense is always paid by somewhere.');
        $this->assertSame($this->owner->id, $first->user_id);
    }

    public function test_a_new_business_starts_with_usable_categories(): void
    {
        $this->setUpBusiness();

        // An empty dropdown on the first expense form is a dead end.
        $this->assertGreaterThan(0, ExpenseCategory::query()->count());
        $this->assertNotNull(ExpenseCategory::query()->where('name', 'Rent')->first());
    }

    public function test_an_amount_of_zero_is_refused(): void
    {
        $this->setUpBusiness();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('not a record of anything');

        $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 0,
        ]);
    }

    // ================================================== the drawer stays honest

    public function test_a_cash_expense_comes_out_of_the_open_till(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(2000);

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 750,
            'payment_method' => 'cash',
        ]);

        $session = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame(750.0, (float) $session->cash_out, 'The drawer is 750 lighter.');
        $this->assertSame($session->id, $expense->cash_session_id);
    }

    public function test_a_bank_transfer_does_not_touch_the_till(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(2000);

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 750,
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertSame(0.0, (float) CashSession::query()->allBranches()->findOrFail($session->id)->cash_out);
        $this->assertNull($expense->cash_session_id, 'It never came out of a drawer.');
    }

    public function test_editing_a_cash_expense_moves_the_drawer_by_the_difference(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(2000);

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 500,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(500.0, (float) CashSession::query()->allBranches()->findOrFail($session->id)->cash_out);

        // A correction is not a second payment: 500 → 400 should leave the till
        // 100 better off, not 400 worse.
        $this->expenses()->update($expense, ['amount' => 400]);

        $session = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame(500.0, (float) $session->cash_out);
        $this->assertSame(100.0, (float) $session->cash_in, 'The extra 100 went back in.');
        $this->assertSame(400.0, round((float) $session->cash_out - (float) $session->cash_in, 2), 'Net 400 out.');
    }

    public function test_deleting_a_cash_expense_puts_the_money_back(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(2000);

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 300,
            'payment_method' => 'cash',
        ]);

        $this->expenses()->delete($expense);

        $session = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame(300.0, (float) $session->cash_out, 'The original payment is still on the record.');
        $this->assertSame(300.0, (float) $session->cash_in, 'And what was taken out came back.');
        $this->assertSame(0.0, round((float) $session->cash_out - (float) $session->cash_in, 2), 'Net: nothing left the till.');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_closed_session_is_not_rewritten_by_a_later_edit(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(2000);

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 500,
            'payment_method' => 'cash',
        ]);

        // Counted, signed off, closed. The 500 is already inside that figure.
        app(CashSessionService::class)->close($session->fresh(), 1500);

        $closed = CashSession::query()->allBranches()->findOrFail($session->id);
        $stampedDifference = (float) $closed->difference;
        $stampedOut = (float) $closed->cash_out;

        $this->expenses()->update($expense, ['amount' => 900]);

        $closed = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame($stampedOut, (float) $closed->cash_out, 'A closed till is history.');
        $this->assertSame($stampedDifference, (float) $closed->difference);
        $this->assertSame(900.0, (float) $expense->fresh()->amount, 'The expense itself was still corrected.');
    }

    public function test_cash_income_goes_into_the_till(): void
    {
        $this->setUpBusiness();
        $session = $this->openTill(1000);

        $income = $this->expenses()->createIncome([
            'amount' => 1200,
            'source' => 'Scrap cartons sold',
            'payment_method' => 'cash',
        ]);

        $session = CashSession::query()->allBranches()->findOrFail($session->id);

        $this->assertSame('INC-000001', $income->reference);
        $this->assertSame(1200.0, (float) $session->cash_in);
        $this->assertSame(0.0, (float) $session->cash_out);
    }

    public function test_income_needs_a_source(): void
    {
        $this->setUpBusiness();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Say where the money came from.');

        $this->expenses()->createIncome(['amount' => 500, 'source' => '  ']);
    }

    // ========================================================== the categories

    public function test_a_category_in_use_is_switched_off_not_deleted(): void
    {
        $this->setUpBusiness();
        $category = $this->expenses()->createCategory(['name' => 'Packaging']);

        $this->expenses()->create(['expense_category_id' => $category->id, 'amount' => 100]);

        $this->assertFalse($category->fresh()->canBeDeleted());

        try {
            $this->expenses()->deleteCategory($category->fresh());
            $this->fail('A category holding figures should not be deletable.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Switch it off instead', $e->getMessage());
        }

        // …but switching it off is always allowed.
        $this->expenses()->updateCategory($category, ['is_active' => false]);
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $this->setUpBusiness();
        $category = $this->expenses()->createCategory(['name' => 'Temporary']);

        $this->expenses()->deleteCategory($category);

        $this->assertNull(ExpenseCategory::query()->find($category->id));
    }

    public function test_renaming_a_category_does_not_refile_its_expenses(): void
    {
        $this->setUpBusiness();
        $category = $this->expenses()->createCategory(['name' => 'Fuel']);
        $expense = $this->expenses()->create(['expense_category_id' => $category->id, 'amount' => 250]);

        $this->expenses()->updateCategory($category, ['name' => 'Fuel & tolls']);

        $this->assertSame($category->id, $expense->fresh()->expense_category_id);
        $this->assertSame('Fuel & tolls', $expense->fresh()->category->name);
    }

    // ============================================================ the receipt

    public function test_a_receipt_is_stored_replaced_and_removed(): void
    {
        $this->setUpBusiness();
        $category = $this->category();

        $expense = $this->expenses()->create([
            'expense_category_id' => $category->id,
            'amount' => 1000,
            'attachment' => UploadedFile::fake()->image('bill.jpg'),
        ]);

        $first = $expense->attachment_path;

        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $this->assertSame('bill.jpg', $expense->attachment_name);

        // A new file REPLACES the old one — one expense, one receipt.
        $this->expenses()->update($expense, [
            'attachment' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
        ]);

        $expense->refresh();

        $this->assertNotSame($first, $expense->attachment_path);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($expense->attachment_path);
        $this->assertSame('invoice.pdf', $expense->attachment_name);

        $second = $expense->attachment_path;

        $this->expenses()->update($expense, ['remove_attachment' => true]);

        $this->assertNull($expense->fresh()->attachment_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_a_refused_expense_leaves_no_file_behind(): void
    {
        $this->setUpBusiness();

        try {
            $this->expenses()->create([
                'expense_category_id' => 999999,   // no such category
                'amount' => 100,
                'attachment' => UploadedFile::fake()->image('orphan.jpg'),
            ]);
            $this->fail('An expense with no category should be refused.');
        } catch (HttpException) {
            // expected
        }

        $this->assertEmpty(Storage::disk('public')->files('receipts'), 'No orphan on disk.');
    }

    public function test_deleting_an_expense_takes_its_receipt_with_it(): void
    {
        $this->setUpBusiness();

        $expense = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 100,
            'attachment' => UploadedFile::fake()->image('bill.png'),
        ]);

        $path = $expense->attachment_path;

        $this->expenses()->delete($expense);

        Storage::disk('public')->assertMissing($path);
    }

    // ================================================== through the interface

    public function test_the_form_records_an_expense_with_its_receipt(): void
    {
        $this->setUpBusiness();
        $category = $this->category('Transport');

        $this->actingAs($this->owner)
            ->get(route('app.expenses.create'))
            ->assertOk()
            ->assertSee('What was the money spent on?')
            ->assertSee('Transport');

        $this->actingAs($this->owner)->post(route('app.expenses.store'), [
            'expense_category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'amount' => 3200,
            'payment_method' => 'cash',
            'payee' => 'Rider',
            'attachment' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertRedirect(route('app.expenses.index'));

        $expense = Expense::query()->allBranches()->firstOrFail();

        $this->assertSame(3200.0, (float) $expense->amount);
        Storage::disk('public')->assertExists($expense->attachment_path);

        $this->actingAs($this->owner)
            ->get(route('app.expenses.index'))
            ->assertOk()
            ->assertSee('EXP-000001')
            ->assertSee('3,200.00');
    }

    public function test_the_form_refuses_a_future_dated_expense(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.expenses.store'), [
            'expense_category_id' => $this->category()->id,
            'expense_date' => now()->addDay()->toDateString(),
            'amount' => 100,
        ])->assertSessionHasErrors('expense_date');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_form_refuses_a_file_that_is_not_a_receipt(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.expenses.store'), [
            'expense_category_id' => $this->category()->id,
            'expense_date' => now()->toDateString(),
            'amount' => 100,
            'attachment' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('attachment');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_two_live_categories_cannot_share_a_name(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.expense-categories.store'), ['name' => 'Rent', 'is_active' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_the_income_form_records_and_lists(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.income.store'), [
            'income_date' => now()->toDateString(),
            'amount' => 850,
            'source' => 'Sublet corner',
            'payment_method' => 'cash',
        ])->assertRedirect(route('app.income.index'));

        $this->assertSame(850.0, (float) OtherIncome::query()->allBranches()->firstOrFail()->amount);

        $this->actingAs($this->owner)
            ->get(route('app.income.index'))
            ->assertOk()
            ->assertSee('Sublet corner')
            ->assertSee('850.00');
    }

    // ============================================== who is allowed to do this

    public function test_viewing_and_recording_are_separate_authorities(): void
    {
        $this->setUpBusiness();

        $viewer = $this->userWith([PermissionRegistry::EXPENSES_VIEW]);

        $this->actingAs($viewer)->get(route('app.expenses.index'))->assertOk();
        $this->actingAs($viewer)->get(route('app.expenses.create'))->assertRedirect();
        $this->actingAs($viewer)
            ->post(route('app.expenses.store'), [
                'expense_category_id' => $this->category()->id,
                'expense_date' => now()->toDateString(),
                'amount' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHas('permission_denied');

        $this->assertSame(0, Expense::query()->allBranches()->count());

        $bookkeeper = $this->userWith([PermissionRegistry::EXPENSES_VIEW, PermissionRegistry::EXPENSES_MANAGE]);
        $this->actingAs($bookkeeper)->get(route('app.expenses.create'))->assertOk();
    }

    public function test_a_plan_without_expense_tracking_cannot_reach_them(): void
    {
        $this->setUpBusiness([FeatureRegistry::ACCOUNTING_EXPENSES => false]);

        $this->actingAs($this->owner)
            ->get(route('app.expenses.index'))
            ->assertRedirect(route('app.billing.index'));

        $this->actingAs($this->owner)
            ->get(route('app.income.index'))
            ->assertRedirect(route('app.billing.index'));

        $this->expectException(FeatureUnavailableException::class);
        $this->expenses()->create(['expense_category_id' => 1, 'amount' => 100]);
    }

    public function test_another_shops_expense_does_not_exist(): void
    {
        $this->setUpBusiness();

        $mine = $this->expenses()->create([
            'expense_category_id' => $this->category()->id,
            'amount' => 100,
        ]);

        // ⚠️ The tenant stamp has to come off before building the second shop,
        // or BelongsToTenant plants its owner in the first one.
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
            ->get(route('app.expenses.edit', $mine))
            ->assertNotFound();

        $this->actingAs($otherOwner)
            ->get(route('app.expenses.index'))
            ->assertOk()
            ->assertDontSee($mine->reference);
    }
}
