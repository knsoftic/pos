<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\LedgerAdjustmentRequest;
use App\Http\Requests\App\LedgerPaymentRequest;
use App\Http\Requests\App\SupplierRequest;
use App\Models\Supplier;
use App\Services\PlanLimitService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierService;
use App\Support\LimitRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suppliers and their accounts (#38, #42).
 *
 * The ledger actions live here rather than in a second controller, unlike the
 * customer side: a supplier's screens are only ever used by the people who also
 * settle their bills, so splitting them would add a class without adding a
 * boundary. The route gates are still separate authorities.
 */
class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $suppliers,
        protected SupplierLedgerService $ledger,
        protected PlanLimitService $limits,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'balance' => (string) $request->query('balance', ''),
        ];

        return view('app.suppliers.index', [
            'suppliers' => Supplier::query()
                ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
                ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
                ->when($filters['status'] === 'blocked', fn (Builder $q) => $q->where('is_active', false))
                ->when($filters['balance'] === 'owed', fn (Builder $q) => $q->owed())
                ->when($filters['balance'] === 'advance', fn (Builder $q) => $q->where('balance', '<', 0))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => $filters,
            'meter' => $this->limits->meter(LimitRegistry::SUPPLIERS),
            'totals' => [
                'payable' => round((float) Supplier::query()->where('balance', '>', 0)->sum('balance'), 2),
                'advances' => round(abs((float) Supplier::query()->where('balance', '<', 0)->sum('balance')), 2),
                'owed_count' => Supplier::query()->owed()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('app.suppliers.create', ['supplier' => new Supplier]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        return redirect()
            ->route('app.suppliers.show', $supplier)
            ->with('success', "\"{$supplier->name}\" added.");
    }

    /** The profile: bought, returned, paid, outstanding — then the statement (#38). */
    public function show(Request $request, Supplier $supplier): View
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return view('app.suppliers.show', [
            'supplier' => $supplier,
            'summary' => $this->ledger->summary($supplier),
            'entries' => $this->ledger->statement($supplier, $from, $to)->paginate(30)->withQueryString(),
            'totals' => $this->ledger->totals($supplier, $from, $to),
            'from' => $from,
            'to' => $to,
            'paymentMethods' => config('subscription.payment_methods', []),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('app.suppliers.edit', ['supplier' => $supplier]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->validated());

        return redirect()
            ->route('app.suppliers.show', $supplier)
            ->with('success', "\"{$supplier->name}\" updated.");
    }

    public function toggle(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'blocked_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->suppliers->setActive($supplier, ! $supplier->is_active, $validated['blocked_reason'] ?? null);

        return back()->with('success', sprintf(
            '"%s" %s.',
            $supplier->name,
            $supplier->is_active ? 'unblocked' : 'blocked',
        ));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if (! $this->suppliers->delete($supplier)) {
            return back()->with('error', 'That supplier has a statement and cannot be deleted. Block them instead — nothing is lost.');
        }

        return redirect()
            ->route('app.suppliers.index')
            ->with('success', 'Supplier deleted.');
    }

    // -------------------------------------------------------------- ledger

    /** Money paid out to the supplier (#42). */
    public function payment(LedgerPaymentRequest $request, Supplier $supplier): RedirectResponse
    {
        $entry = $this->ledger->recordPayment($supplier, (float) $request->input('amount'), [
            'entry_date' => $request->input('entry_date'),
            'payment_method' => $request->input('payment_method'),
            'reference_no' => $request->input('reference_no'),
            'description' => $request->input('description') ?: 'Payment made',
        ]);

        return back()->with('success', sprintf(
            'Paid %s. %s',
            number_format($entry->amount(), 2),
            $this->balanceSentence($supplier),
        ));
    }

    public function adjustment(LedgerAdjustmentRequest $request, Supplier $supplier): RedirectResponse
    {
        $entry = $this->ledger->adjust(
            $supplier,
            (float) $request->input('amount'),
            $request->string('reason')->toString(),
            $request->input('entry_date'),
        );

        return back()->with('success', sprintf(
            'Account adjusted by %s. %s',
            number_format($entry->amount(), 2),
            $this->balanceSentence($supplier),
        ));
    }

    protected function balanceSentence(Supplier $supplier): string
    {
        $supplier->refresh();

        return match ($supplier->balanceDirection()) {
            'settled' => 'The account is now settled.',
            'owing' => 'You now owe '.number_format($supplier->weOwe(), 2).'.',
            default => 'They now hold '.number_format($supplier->theyOweUs(), 2).' of yours in advance.',
        };
    }
}
