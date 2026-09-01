<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\OtherIncome;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the business spends, and what it takes in that was not a sale
 * (#43, #44).
 *
 * ================= THE LINE THIS SERVICE DEFENDS =================
 * STOCK IS NOT AN EXPENSE. A delivery of goods is a purchase: its cost sits in
 * inventory and reaches the profit figure through COGS on the day the goods
 * sell. Rent reaches it on the day it is due. Booking a stock purchase here
 * would count the same money twice — once as an expense now, once as COGS when
 * it sells — and the P&L would report a loss every month the shop restocked.
 * Nothing here ever touches `stocks` or `stock_movements`, and that is on
 * purpose.
 *
 * ================= WHY CASH GETS SPECIAL TREATMENT =================
 * Paying the window cleaner out of the till really does empty the drawer. If
 * that never reached the open cash session, the cash-up would report a shortfall
 * every time and the one signal that matters — "is the till actually short?" —
 * would be buried in noise. So a cash expense moves `cash_out`, cash income
 * moves `cash_in` (#46), and both go through {@see CashSessionService} rather
 * than writing the columns here, so there is one place that knows how.
 *
 * ================= EDITING AND DELETING =================
 * Unlike a sale or a return, an expense IS editable and IS deletable. It is a
 * bookkeeping entry, not a document handed to a customer: nobody outside holds
 * a copy, and a typo in last week's electricity bill has no honest correction
 * other than fixing it. Every change is audited, and a cash expense that is
 * edited or removed puts the drawer back where it should be.
 */
class ExpenseService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected CashSessionService $cashSessions,
        protected AuditService $audit,
    ) {}

    // ============================================================ categories

    /**
     * The starting set of headings, so the first expense form is not a dead end
     * (#43). Deliberately generic and few: the shop will add what it actually
     * needs, and a list of thirty guesses is harder to prune than five is to
     * extend.
     */
    public function seedDefaults(Business $business): void
    {
        $this->tenant->runFor($business, function () use ($business): void {
            if (ExpenseCategory::query()->exists()) {
                return;
            }

            foreach (['Rent', 'Utilities', 'Salaries', 'Transport', 'Repairs & maintenance'] as $order => $name) {
                $category = new ExpenseCategory([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'is_active' => true,
                    'sort_order' => $order,
                ]);
                $category->business_id = $business->id;
                $category->save();
            }
        });
    }

    /** @param  array{name: string, description?: string|null, is_active?: bool, sort_order?: int}  $data */
    public function createCategory(array $data): ExpenseCategory
    {
        $this->assertFeature();

        $category = new ExpenseCategory([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $category->save();

        $this->audit->log('expense_category.created', $category, "Expense category \"{$category->name}\" created.");

        return $category;
    }

    /** @param  array<string, mixed>  $data */
    public function updateCategory(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $this->assertFeature();

        $before = $category->only(['name', 'description', 'is_active', 'sort_order']);

        if (array_key_exists('name', $data) && trim($data['name']) !== $category->name) {
            $category->name = trim($data['name']);
            $category->slug = $this->uniqueSlug($data['name'], $category->id);
        }

        foreach (['description', 'is_active', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $category->{$field} = $data[$field];
            }
        }

        $category->save();

        $this->audit->logChange(
            'expense_category.updated',
            $category,
            $before,
            $category->only(['name', 'description', 'is_active', 'sort_order']),
            "Expense category \"{$category->name}\" updated.",
        );

        return $category;
    }

    /**
     * Archived once used (#104). The expenses filed under it are what a past
     * P&L reads; removing the heading would leave those figures unlabelled.
     */
    public function deleteCategory(ExpenseCategory $category): void
    {
        $this->assertFeature();

        abort_if(
            $category->isInUse(),
            422,
            'Expenses are filed under this category. Switch it off instead — past figures still need the heading.',
        );

        $name = $category->name;
        $category->delete();

        $this->audit->log('expense_category.deleted', $category, "Expense category \"{$name}\" deleted.");
    }

    // ============================================================== expenses

    /**
     * @param  array{expense_category_id: int, amount: float, expense_date?: string|null,
     *               branch_id?: int|null, payment_method?: string|null, payee?: string|null,
     *               bill_no?: string|null, note?: string|null, attachment?: UploadedFile|null}  $data
     */
    public function create(array $data): Expense
    {
        $this->assertFeature();

        $branchId = $this->resolveBranch($data['branch_id'] ?? null);
        $category = $this->resolveCategory($data['expense_category_id'] ?? null);
        $amount = $this->resolveAmount($data['amount'] ?? null);
        $method = (string) ($data['payment_method'] ?? 'cash');

        $attachment = $this->storeAttachment($data['attachment'] ?? null);

        try {
            return DB::transaction(function () use ($data, $branchId, $category, $amount, $method, $attachment): Expense {
                $user = Auth::guard('web')->user();

                // Only a cash payment can have come out of a drawer.
                $session = $this->isCashMethod($method)
                    ? $this->cashSessions->currentFor($branchId)
                    : null;

                $expense = new Expense([
                    'branch_id' => $branchId,
                    'expense_category_id' => $category->id,
                    'cash_session_id' => $session?->id,
                    'reference' => $this->nextReference(Expense::class, 'EXP'),
                    'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                    'amount' => $amount,
                    'payment_method' => $method,
                    'payee' => $data['payee'] ?? null,
                    'bill_no' => $data['bill_no'] ?? null,
                    'note' => $data['note'] ?? null,
                    'attachment_path' => $attachment['path'],
                    'attachment_name' => $attachment['name'],
                    'attachment_size' => $attachment['size'],
                    'user_id' => $user?->id,
                    'user_name' => $user?->name,
                ]);
                $expense->save();

                if ($session !== null) {
                    $this->cashSessions->recordMovement(
                        $session,
                        $amount,
                        sprintf('%s — %s', $expense->reference, $category->name),
                        isIn: false,
                    );
                }

                $this->audit->log(
                    'expense.created',
                    $expense,
                    sprintf('%s: %s spent on %s.', $expense->reference, number_format($amount, 2), $category->name),
                    ['amount' => $amount, 'category' => $category->name, 'method' => $method],
                );

                return $expense;
            });
        } catch (\Throwable $e) {
            // The file was written before the transaction opened, so a refused
            // expense must not leave an orphan on disk.
            $this->deleteAttachment($attachment['path']);

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Expense $expense, array $data): Expense
    {
        $this->assertFeature();

        abort_unless($this->branches->allows($expense->branch_id), 403, 'That branch is outside your access.');

        $before = $expense->only(['expense_category_id', 'amount', 'expense_date', 'payment_method', 'payee', 'bill_no', 'note']);
        $oldAmount = (float) $expense->amount;
        $oldCash = $expense->isCash();

        $category = array_key_exists('expense_category_id', $data)
            ? $this->resolveCategory($data['expense_category_id'])
            : $expense->category;

        $amount = array_key_exists('amount', $data)
            ? $this->resolveAmount($data['amount'])
            : $oldAmount;

        $method = array_key_exists('payment_method', $data)
            ? (string) $data['payment_method']
            : (string) $expense->payment_method;

        $attachment = $this->storeAttachment($data['attachment'] ?? null);
        $replacedPath = null;

        try {
            $updated = DB::transaction(function () use ($expense, $data, $category, $amount, $method, $attachment, $oldAmount, $oldCash, $before, &$replacedPath): Expense {
                $expense->expense_category_id = $category->id;
                $expense->amount = $amount;
                $expense->payment_method = $method;

                foreach (['expense_date', 'payee', 'bill_no', 'note'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $expense->{$field} = $data[$field];
                    }
                }

                // An attachment is REPLACED, never accumulated: one expense, one
                // receipt, and the old file goes when the new one lands.
                if ($attachment['path'] !== null) {
                    $replacedPath = $expense->attachment_path;
                    $expense->attachment_path = $attachment['path'];
                    $expense->attachment_name = $attachment['name'];
                    $expense->attachment_size = $attachment['size'];
                } elseif ($data['remove_attachment'] ?? false) {
                    $replacedPath = $expense->attachment_path;
                    $expense->attachment_path = null;
                    $expense->attachment_name = null;
                    $expense->attachment_size = null;
                }

                $expense->save();

                // Put the drawer back where it should be. The old figure comes
                // off and the new one goes on, so a cash expense edited to a
                // card one leaves no trace in the till.
                $this->adjustDrawer(
                    $expense->cash_session_id,
                    was: $oldCash ? $oldAmount : 0.0,
                    now: $expense->isCash() ? $amount : 0.0,
                    reason: sprintf('%s corrected', $expense->reference),
                );

                $this->audit->logChange(
                    'expense.updated',
                    $expense,
                    $before,
                    $expense->only(['expense_category_id', 'amount', 'expense_date', 'payment_method', 'payee', 'bill_no', 'note']),
                    "{$expense->reference} updated.",
                );

                return $expense;
            });

            // Committed: the file it replaced is now unreferenced.
            $this->deleteAttachment($replacedPath);

            return $updated;
        } catch (\Throwable $e) {
            // The new file was written before the transaction opened, so a
            // refused edit must not leave an orphan on disk — and the file it
            // was going to replace is still the live one, so it stays.
            $this->deleteAttachment($attachment['path']);

            throw $e;
        }
    }

    public function delete(Expense $expense): void
    {
        $this->assertFeature();

        abort_unless($this->branches->allows($expense->branch_id), 403, 'That branch is outside your access.');

        $path = $expense->attachment_path;
        $reference = $expense->reference;
        $amount = (float) $expense->amount;
        $wasCash = $expense->isCash();
        $sessionId = $expense->cash_session_id;

        DB::transaction(function () use ($expense, $reference, $amount, $wasCash, $sessionId): void {
            $expense->delete();

            $this->adjustDrawer(
                $sessionId,
                was: $wasCash ? $amount : 0.0,
                now: 0.0,
                reason: sprintf('%s removed', $reference),
            );

            $this->audit->log(
                'expense.deleted',
                $expense,
                sprintf('%s deleted (%s).', $reference, number_format($amount, 2)),
                ['amount' => $amount],
            );
        });

        $this->deleteAttachment($path);
    }

    // ========================================================= other income

    /**
     * @param  array{amount: float, source: string, income_date?: string|null, branch_id?: int|null,
     *               payment_method?: string|null, note?: string|null, attachment?: UploadedFile|null}  $data
     */
    public function createIncome(array $data): OtherIncome
    {
        $this->assertFeature();

        $branchId = $this->resolveBranch($data['branch_id'] ?? null);
        $amount = $this->resolveAmount($data['amount'] ?? null);
        $method = (string) ($data['payment_method'] ?? 'cash');

        abort_if(blank($data['source'] ?? null), 422, 'Say where the money came from.');

        $attachment = $this->storeAttachment($data['attachment'] ?? null);

        try {
            return DB::transaction(function () use ($data, $branchId, $amount, $method, $attachment): OtherIncome {
                $user = Auth::guard('web')->user();

                $session = $this->isCashMethod($method)
                    ? $this->cashSessions->currentFor($branchId)
                    : null;

                $income = new OtherIncome([
                    'branch_id' => $branchId,
                    'cash_session_id' => $session?->id,
                    'reference' => $this->nextReference(OtherIncome::class, 'INC'),
                    'income_date' => $data['income_date'] ?? now()->toDateString(),
                    'amount' => $amount,
                    'payment_method' => $method,
                    'source' => trim((string) $data['source']),
                    'note' => $data['note'] ?? null,
                    'attachment_path' => $attachment['path'],
                    'attachment_name' => $attachment['name'],
                    'attachment_size' => $attachment['size'],
                    'user_id' => $user?->id,
                    'user_name' => $user?->name,
                ]);
                $income->save();

                if ($session !== null) {
                    $this->cashSessions->recordMovement(
                        $session,
                        $amount,
                        sprintf('%s — %s', $income->reference, $income->source),
                        isIn: true,
                    );
                }

                $this->audit->log(
                    'other_income.created',
                    $income,
                    sprintf('%s: %s received from %s.', $income->reference, number_format($amount, 2), $income->source),
                    ['amount' => $amount, 'source' => $income->source, 'method' => $method],
                );

                return $income;
            });
        } catch (\Throwable $e) {
            $this->deleteAttachment($attachment['path']);

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function updateIncome(OtherIncome $income, array $data): OtherIncome
    {
        $this->assertFeature();

        abort_unless($this->branches->allows($income->branch_id), 403, 'That branch is outside your access.');

        $before = $income->only(['amount', 'income_date', 'payment_method', 'source', 'note']);
        $oldAmount = (float) $income->amount;
        $oldCash = $income->isCash();

        $amount = array_key_exists('amount', $data) ? $this->resolveAmount($data['amount']) : $oldAmount;
        $method = array_key_exists('payment_method', $data) ? (string) $data['payment_method'] : (string) $income->payment_method;

        $attachment = $this->storeAttachment($data['attachment'] ?? null);
        $replacedPath = null;

        try {
            $updated = DB::transaction(function () use ($income, $data, $amount, $method, $attachment, $oldAmount, $oldCash, $before, &$replacedPath): OtherIncome {
                $income->amount = $amount;
                $income->payment_method = $method;

                foreach (['income_date', 'source', 'note'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $income->{$field} = $data[$field];
                    }
                }

                if ($attachment['path'] !== null) {
                    $replacedPath = $income->attachment_path;
                    $income->attachment_path = $attachment['path'];
                    $income->attachment_name = $attachment['name'];
                    $income->attachment_size = $attachment['size'];
                } elseif ($data['remove_attachment'] ?? false) {
                    $replacedPath = $income->attachment_path;
                    $income->attachment_path = null;
                    $income->attachment_name = null;
                    $income->attachment_size = null;
                }

                $income->save();

                $this->adjustDrawer(
                    $income->cash_session_id,
                    was: $oldCash ? $oldAmount : 0.0,
                    now: $income->isCash() ? $amount : 0.0,
                    reason: sprintf('%s corrected', $income->reference),
                    isIn: true,
                );

                $this->audit->logChange(
                    'other_income.updated',
                    $income,
                    $before,
                    $income->only(['amount', 'income_date', 'payment_method', 'source', 'note']),
                    "{$income->reference} updated.",
                );

                return $income;
            });

            $this->deleteAttachment($replacedPath);

            return $updated;
        } catch (\Throwable $e) {
            $this->deleteAttachment($attachment['path']);

            throw $e;
        }
    }

    public function deleteIncome(OtherIncome $income): void
    {
        $this->assertFeature();

        abort_unless($this->branches->allows($income->branch_id), 403, 'That branch is outside your access.');

        $path = $income->attachment_path;
        $reference = $income->reference;
        $amount = (float) $income->amount;
        $wasCash = $income->isCash();
        $sessionId = $income->cash_session_id;

        DB::transaction(function () use ($income, $reference, $amount, $wasCash, $sessionId): void {
            $income->delete();

            $this->adjustDrawer(
                $sessionId,
                was: $wasCash ? $amount : 0.0,
                now: 0.0,
                reason: sprintf('%s removed', $reference),
                isIn: true,
            );

            $this->audit->log(
                'other_income.deleted',
                $income,
                sprintf('%s deleted (%s).', $reference, number_format($amount, 2)),
                ['amount' => $amount],
            );
        });

        $this->deleteAttachment($path);
    }

    // ------------------------------------------------------------- internals

    /**
     * Move the drawer by the DIFFERENCE, not the new figure.
     *
     * A correction is not a second payment: an expense edited from 500 to 400
     * should leave the till 100 better off, not 400 worse. Same reasoning as a
     * stock take posting the difference rather than the count.
     */
    protected function adjustDrawer(?int $sessionId, float $was, float $now, string $reason, bool $isIn = false): void
    {
        if ($sessionId === null) {
            return;
        }

        $delta = round($now - $was, 2);

        if (abs($delta) < 0.005) {
            return;
        }

        $session = CashSession::query()->allBranches()->find($sessionId);

        // A closed session is history: its difference was stamped at close and
        // must not be rewritten by an edit made days later (#46).
        if ($session === null || ! $session->isOpen()) {
            return;
        }

        $this->cashSessions->recordMovement(
            $session,
            abs($delta),
            $reason,
            isIn: $delta > 0 ? $isIn : ! $isIn,
        );
    }

    protected function resolveBranch(mixed $branchId): int
    {
        $branchId = filled($branchId)
            ? (int) $branchId
            : (int) (Auth::guard('web')->user()?->branch_id ?? 0);

        if ($branchId === 0) {
            $branchId = (int) (Branch::query()->where('is_main', true)->value('id')
                ?? Branch::query()->orderBy('id')->value('id')
                ?? 0);
        }

        abort_if($branchId === 0, 422, 'An expense needs a branch.');
        abort_unless($this->branches->allows($branchId), 403, 'That branch is outside your access.');

        return $branchId;
    }

    protected function resolveCategory(mixed $categoryId): ExpenseCategory
    {
        $category = ExpenseCategory::query()->find((int) $categoryId);

        abort_if($category === null, 422, 'Choose a category for this expense.');

        return $category;
    }

    protected function resolveAmount(mixed $amount): float
    {
        $amount = round((float) $amount, 2);

        abort_if($amount <= 0, 422, 'An amount of zero is not a record of anything.');

        return $amount;
    }

    protected function isCashMethod(string $method): bool
    {
        return in_array($method, (array) config('pos.cash_methods', ['cash']), true);
    }

    /**
     * Store the receipt and describe it (#43, #101).
     *
     * @return array{path: ?string, name: ?string, size: ?int}
     */
    protected function storeAttachment(mixed $file): array
    {
        if (! $file instanceof UploadedFile) {
            return ['path' => null, 'name' => null, 'size' => null];
        }

        $config = config('uploads.receipts');

        return [
            // A random stored name, so nobody can choose where their file lands
            // or overwrite someone else's.
            'path' => $file->store($config['path'], $config['disk']),
            // The name they knew it by, kept only for the download link.
            'name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'size' => $file->getSize() ?: null,
        ];
    }

    protected function deleteAttachment(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(config('uploads.receipts.disk'))->delete($path);
    }

    /**
     * EXP-000001, INC-000001. Per tenant, and gap-free only in the sense that
     * it counts from the highest so far — an expense is not an invoice, so a
     * missing number is a curiosity rather than a compliance problem.
     *
     * @param  class-string<Model>  $model
     */
    protected function nextReference(string $model, string $prefix): string
    {
        $last = $model::query()->allBranches()->orderByDesc('id')->value('reference');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

        return $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (
            ExpenseCategory::query()
                ->withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::ACCOUNTING_EXPENSES)) {
            throw new FeatureUnavailableException(FeatureRegistry::ACCOUNTING_EXPENSES, 'Expense tracking');
        }
    }
}
