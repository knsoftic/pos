<?php

namespace App\Http\Controllers\App;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PurchaseReceiveRequest;
use App\Http\Requests\App\PurchaseRequest;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Purchases (#35, #36).
 *
 * Thin, as everything financial in this codebase is: the whole receipt flow —
 * stock, supplier ledger, payment, all in one transaction (#119) — belongs to
 * {@see PurchaseService}, because those three have to succeed or fail together
 * and a controller is the wrong place to hold a transaction open.
 */
class PurchaseController extends Controller
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'supplier' => (string) $request->query('supplier', ''),
            'payment' => (string) $request->query('payment', ''),
        ];

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'branch:id,name', 'items'])
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['status'] === '', fn (Builder $q) => $q)
            ->when($filters['supplier'] !== '', fn (Builder $q) => $q->where('supplier_id', (int) $filters['supplier']))
            ->when($filters['payment'] === 'unpaid', fn (Builder $q) => $q->unpaid())
            ->newestFirst()
            ->paginate(25)
            ->withQueryString();

        return view('app.purchases.index', [
            'purchases' => $purchases,
            'filters' => $filters,
            'statuses' => PurchaseStatus::options(),
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'totals' => $this->headlineTotals(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('app.purchases.create', $this->formData(new Purchase, $request));
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $purchase = $this->purchases->create($request->purchaseAttributes(), $request->lineRows());

        return redirect()
            ->route('app.purchases.show', $purchase)
            ->with('success', "Purchase {$purchase->reference} drafted.");
    }

    public function show(Request $request, Purchase $purchase): View
    {
        $purchase->load([
            'items.product:id,name,sku,track_inventory,type,tracks_batches',
            'items.variant:id,name',
            'items.returnItems',
            'supplier',
            'branch:id,name',
            'creator:id,name',
            'returns.items',
        ]);

        return view('app.purchases.show', [
            'purchase' => $purchase,
            'paymentMethods' => config('subscription.payment_methods', []),
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        ]);
    }

    public function edit(Request $request, Purchase $purchase): View
    {
        abort_unless($purchase->status->isEditable(), 422, 'Only a draft can be edited.');

        $purchase->load('items');

        return view('app.purchases.edit', $this->formData($purchase, $request));
    }

    public function update(PurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->purchases->update($purchase, $request->purchaseAttributes(), $request->lineRows());

        return redirect()
            ->route('app.purchases.show', $purchase)
            ->with('success', "Purchase {$purchase->reference} updated.");
    }

    /** Send it to the supplier. Posts nothing — see PurchaseStatus. */
    public function order(Purchase $purchase): RedirectResponse
    {
        $this->purchases->order($purchase);

        return back()->with('success', "Purchase {$purchase->reference} sent to {$purchase->supplier?->name}.");
    }

    /** The goods arrive (#35, #119). */
    public function receive(PurchaseReceiveRequest $request, Purchase $purchase): RedirectResponse
    {
        $purchase = $this->purchases->receive($purchase, $request->receivedQuantities(), [
            'received_date' => $request->input('received_date'),
            'pay_now' => $request->input('pay_now'),
            'payment_method' => $request->input('payment_method'),
            'payment_reference_no' => $request->input('payment_reference_no'),
        ]);

        return back()->with('success', sprintf(
            '%s %s. %s',
            $purchase->reference,
            $purchase->status === PurchaseStatus::Received ? 'fully received' : 'partly received',
            $purchase->isSettled()
                ? 'The bill is settled.'
                : number_format($purchase->balanceDue(), 2).' still to pay.',
        ));
    }

    /** Pay against this bill. The money lands on the supplier's account (#42). */
    public function settle(Request $request, Purchase $purchase): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $purchase = $this->purchases->settle($purchase, (float) $validated['amount'], $validated);

        return back()->with('success', sprintf(
            'Paid %s. %s',
            number_format((float) $validated['amount'], 2),
            $purchase->isSettled()
                ? 'This bill is now settled.'
                : number_format($purchase->balanceDue(), 2).' still to pay.',
        ));
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why the order is being called off — this goes on the record.',
        ]);

        $this->purchases->cancel($purchase, $validated['reason']);

        return back()->with('success', "Purchase {$purchase->reference} cancelled.");
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        if (! $this->purchases->delete($purchase)) {
            return back()->with('error', 'Only an untouched draft can be deleted. Cancel it instead — the record stays.');
        }

        return redirect()
            ->route('app.purchases.index')
            ->with('success', 'Draft purchase deleted.');
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    protected function formData(Purchase $purchase, Request $request): array
    {
        return [
            'purchase' => $purchase,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->accessible()->ordered()->get(['id', 'name']),
            // The whole catalogue would be too much for a select on a big
            // tenant; this is the searchable list the line picker filters.
            'products' => Product::query()
                ->active()
                ->with('variants:id,product_id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'type', 'cost_price', 'tax_rate', 'tracks_batches']),
            'defaultBranchId' => $request->user()->branch_id,
        ];
    }

    /** @return array<string, float|int> */
    protected function headlineTotals(): array
    {
        return [
            'open' => Purchase::query()->open()->count(),
            'unpaid_value' => round((float) Purchase::query()
                ->unpaid()
                ->get()
                ->sum(fn (Purchase $p) => $p->balanceDue()), 2),
            'received_this_month' => round((float) Purchase::query()
                ->whereIn('status', [PurchaseStatus::Partial->value, PurchaseStatus::Received->value])
                ->whereBetween('order_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->get()
                ->sum(fn (Purchase $p) => $p->receivedValue()), 2),
        ];
    }
}
