<?php

namespace App\Http\Controllers\App;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\SaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Goods coming back from a customer (#53, #140).
 *
 * Thin: everything that matters — what may come back, what goes on the shelf,
 * where the money goes — belongs to {@see SaleReturnService}, because those all
 * have to succeed or fail together.
 */
class SaleReturnController extends Controller
{
    public function __construct(protected SaleReturnService $returns) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $query = SaleReturn::query()
            ->with(['sale:id,invoice_no', 'customer:id,name', 'branch:id,name', 'user:id,name', 'items'])
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('return_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('return_date', '<=', $filters['to']))
            ->newestFirst();

        // Counted from the same filtered query the table renders, so the cards
        // and the rows can never tell different stories.
        $summary = (clone $query)->reorder()->get(['id', 'total', 'refunded_amount', 'credited_amount']);

        return view('app.returns.index', [
            'returns' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'totals' => [
                'count' => $summary->count(),
                'value' => round((float) $summary->sum('total'), 2),
                'refunded' => round((float) $summary->sum('refunded_amount'), 2),
                'credited' => round((float) $summary->sum('credited_amount'), 2),
            ],
        ]);
    }

    /** The form, built from what is still returnable on the sale. */
    public function create(Sale $sale): View
    {
        abort_unless(
            $sale->status === SaleStatus::Completed,
            422,
            'Only a completed sale can be returned against.',
        );

        $sale->load(['items.product:id,name,sku,track_inventory,type', 'items.returnItems', 'customer']);

        return view('app.returns.create', [
            'sale' => $sale,
            'paymentMethods' => (array) config('pos.payment_methods', []),
        ]);
    }

    public function store(SaleReturnRequest $request, Sale $sale): RedirectResponse
    {
        $return = $this->returns->create($sale, $request->returnAttributes(), $request->lines());

        return redirect()
            ->route('app.returns.show', $return)
            ->with('success', sprintf(
                'Return %s recorded — %s.',
                $return->reference,
                $return->settlementLabel(),
            ));
    }

    public function show(Request $request, SaleReturn $saleReturn): View
    {
        $saleReturn->load([
            'items.product:id,name,sku',
            'items.variant:id,name',
            'sale:id,invoice_no,sold_at',
            'customer',
            'branch:id,name',
            'user:id,name',
        ]);

        return view('app.returns.show', [
            'return' => $saleReturn,
            'canSeeProfit' => $request->user()->can(PermissionRegistry::REPORTS_VIEW_PROFIT),
        ]);
    }
}
