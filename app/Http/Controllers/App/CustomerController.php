<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use App\Services\PlanLimitService;
use App\Support\LimitRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customers (#39, #40, #105).
 *
 * No branch filtering anywhere in here, deliberately: a customer belongs to the
 * business, not to the shop they first walked into (#137). Their balance has to
 * be the same number at every till.
 */
class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customers,
        protected CustomerLedgerService $ledger,
        protected PlanLimitService $limits,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'balance' => (string) $request->query('balance', ''),
        ];

        $customers = Customer::query()
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'blocked', fn (Builder $q) => $q->where('is_active', false))
            ->when($filters['balance'] === 'owing', fn (Builder $q) => $q->owing())
            ->when($filters['balance'] === 'credit', fn (Builder $q) => $q->where('balance', '<', 0))
            ->when($filters['balance'] === 'over_limit', fn (Builder $q) => $q
                ->whereNotNull('credit_limit')
                ->whereColumn('balance', '>', 'credit_limit'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('app.customers.index', [
            'customers' => $customers,
            'filters' => $filters,
            'meter' => $this->limits->meter(LimitRegistry::CUSTOMERS),
            'totals' => [
                // Only what is genuinely receivable — an account in credit is
                // not a negative debt, it is money the shop is holding.
                'receivable' => round((float) Customer::query()->where('balance', '>', 0)->sum('balance'), 2),
                'in_credit' => round(abs((float) Customer::query()->where('balance', '<', 0)->sum('balance')), 2),
                'owing_count' => Customer::query()->owing()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('app.customers.create', ['customer' => new Customer]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->validated());

        return redirect()
            ->route('app.customers.show', $customer)
            ->with('success', "\"{$customer->name}\" added.");
    }

    /** The profile: what they have bought, paid and owe, then the statement (#39). */
    public function show(Request $request, Customer $customer): View
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return view('app.customers.show', [
            'customer' => $customer,
            'summary' => $this->ledger->summary($customer),
            'entries' => $this->ledger->statement($customer, $from, $to)->paginate(30)->withQueryString(),
            'totals' => $this->ledger->totals($customer, $from, $to),
            'from' => $from,
            'to' => $to,
            'paymentMethods' => config('subscription.payment_methods', []),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('app.customers.edit', ['customer' => $customer]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());

        return redirect()
            ->route('app.customers.show', $customer)
            ->with('success', "\"{$customer->name}\" updated.");
    }

    /** Block / unblock (#105) — nothing is lost either way. */
    public function toggle(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'blocked_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->customers->setActive($customer, ! $customer->is_active, $validated['blocked_reason'] ?? null);

        return back()->with('success', sprintf(
            '"%s" %s.',
            $customer->name,
            $customer->is_active ? 'unblocked' : 'blocked',
        ));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if (! $this->customers->delete($customer)) {
            return back()->with('error', 'That customer has a statement and cannot be deleted. Block them instead — nothing is lost.');
        }

        return redirect()
            ->route('app.customers.index')
            ->with('success', 'Customer deleted.');
    }
}
