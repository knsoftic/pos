<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\OtherIncomeRequest;
use App\Models\Branch;
use App\Models\OtherIncome;
use App\Services\ExpenseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Money in that was not a sale (#44).
 */
class OtherIncomeController extends Controller
{
    public function __construct(protected ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $query = OtherIncome::query()
            ->with(['branch:id,name', 'user:id,name'])
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->between($filters['from'] ?: null, $filters['to'] ?: null)
            ->newestFirst();

        $summary = (clone $query)->reorder()->toBase()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as v')
            ->first();

        return view('app.income.index', [
            'incomes' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'totals' => [
                'count' => (int) ($summary->c ?? 0),
                'value' => round((float) ($summary->v ?? 0), 2),
            ],
        ]);
    }

    public function create(): View
    {
        return view('app.income.form', [
            'income' => new OtherIncome(['income_date' => now()->toDateString(), 'payment_method' => 'cash']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function store(OtherIncomeRequest $request): RedirectResponse
    {
        $income = $this->expenses->createIncome($request->incomeAttributes());

        return redirect()
            ->route('app.income.index')
            ->with('success', sprintf(
                '%s recorded — %s from %s.',
                $income->reference,
                number_format((float) $income->amount, 2),
                $income->source,
            ));
    }

    public function edit(OtherIncome $income): View
    {
        return view('app.income.form', [
            'income' => $income,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function update(OtherIncomeRequest $request, OtherIncome $income): RedirectResponse
    {
        $this->expenses->updateIncome($income, $request->incomeAttributes());

        return redirect()
            ->route('app.income.index')
            ->with('success', "{$income->reference} updated.");
    }

    public function destroy(OtherIncome $income): RedirectResponse
    {
        $reference = $income->reference;

        $this->expenses->deleteIncome($income);

        return redirect()
            ->route('app.income.index')
            ->with('success', "{$reference} deleted.");
    }

    /** @return list<string> */
    protected function paymentMethods(): array
    {
        return array_values(array_filter(
            (array) config('pos.payment_methods', []),
            fn (string $method) => $method !== config('pos.credit_method'),
        ));
    }
}
