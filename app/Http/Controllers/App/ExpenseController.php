<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\ExpenseRequest;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the business spent (#43).
 *
 * Thin. Every decision that matters — what a cash payment does to the drawer,
 * what an edit does to it afterwards, where the receipt file lives — belongs to
 * {@see ExpenseService}, because those have to happen together or not at all.
 */
class ExpenseController extends Controller
{
    public function __construct(protected ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $query = Expense::query()
            ->with(['category:id,name', 'branch:id,name', 'user:id,name'])
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->when($filters['category'] !== '', fn (Builder $q) => $q->where('expense_category_id', $filters['category']))
            ->between($filters['from'] ?: null, $filters['to'] ?: null)
            ->newestFirst();

        // Totals from the same filtered query the table renders, so the cards
        // and the rows can never tell different stories.
        $summary = (clone $query)->reorder()->toBase()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as v')
            ->first();

        return view('app.expenses.index', [
            'expenses' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'categories' => ExpenseCategory::query()->ordered()->get(['id', 'name', 'is_active']),
            'totals' => [
                'count' => (int) ($summary->c ?? 0),
                'value' => round((float) ($summary->v ?? 0), 2),
            ],
        ]);
    }

    public function create(): View
    {
        return view('app.expenses.form', [
            'expense' => new Expense(['expense_date' => now()->toDateString(), 'payment_method' => 'cash']),
            'categories' => ExpenseCategory::query()->active()->ordered()->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = $this->expenses->create($request->expenseAttributes());

        return redirect()
            ->route('app.expenses.index')
            ->with('success', sprintf(
                '%s recorded — %s to %s.',
                $expense->reference,
                number_format((float) $expense->amount, 2),
                $expense->payee ?: $expense->category?->name,
            ));
    }

    public function edit(Expense $expense): View
    {
        return view('app.expenses.form', [
            'expense' => $expense,
            // The expense's own category is included even if it has since been
            // switched off, so editing an old row does not silently refile it.
            'categories' => ExpenseCategory::query()
                ->where(fn (Builder $q) => $q->active()->orWhereKey($expense->expense_category_id))
                ->ordered()
                ->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->expenses->update($expense, $request->expenseAttributes());

        return redirect()
            ->route('app.expenses.index')
            ->with('success', "{$expense->reference} updated.");
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $reference = $expense->reference;

        $this->expenses->delete($expense);

        return redirect()
            ->route('app.expenses.index')
            ->with('success', "{$reference} deleted.");
    }

    /** @return list<string> */
    protected function paymentMethods(): array
    {
        // Credit is a sale's way of taking no money; it means nothing here.
        return array_values(array_filter(
            (array) config('pos.payment_methods', []),
            fn (string $method) => $method !== config('pos.credit_method'),
        ));
    }
}
