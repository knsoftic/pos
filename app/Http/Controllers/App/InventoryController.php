<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StockAdjustmentRequest;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Services\InventoryService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is on the shelf, and how it got there (#28, #30, #31).
 *
 * Nothing here filters by branch or tenant: {@see Stock} carries both scopes, so
 * a cashier at one shop opening this page sees their own stock and a manager at
 * another sees theirs, with no `where` in this controller to get wrong (#48,
 * #136).
 */
class InventoryController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'branch' => (string) $request->query('branch', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $stocks = Stock::query()
            ->with(['product:id,name,sku,alert_quantity,track_inventory,unit_id', 'product.unit:id,short_name', 'variant:id,name,alert_quantity', 'branch:id,name'])
            ->whereHas('product', fn (Builder $q) => $q->where('track_inventory', true))
            ->when($filters['search'] !== '', fn (Builder $q) => $q->whereHas(
                'product',
                fn (Builder $p) => $p->search($filters['search']),
            ))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            ->when($filters['status'] === 'out', fn (Builder $q) => $q->outOfStock())
            ->when($filters['status'] === 'negative', fn (Builder $q) => $q->where('quantity', '<', 0))
            ->orderBy('quantity')
            ->paginate(25)
            ->withQueryString();

        // The low-stock filter needs the threshold join, which does not survive
        // being mixed with the eager loads above — so it is its own query.
        if ($filters['status'] === 'low') {
            $stocks = $this->inventory->lowStock($filters['branch'] !== '' ? (int) $filters['branch'] : null)
                ->orderBy('stocks.quantity')
                ->paginate(25)
                ->withQueryString();
        }

        return view('app.inventory.index', [
            'stocks' => $stocks,
            'filters' => $filters,
            'branches' => Branch::query()->accessible()->ordered()->get(['id', 'name']),
            'valuation' => $this->inventory->valuation($filters['branch'] !== '' ? (int) $filters['branch'] : null),
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        ]);
    }

    /** One product's full history, across every branch this user can reach (#30). */
    public function ledger(Request $request, Product $product): View
    {
        $branchId = $request->query('branch') !== null ? (int) $request->query('branch') : null;

        return view('app.inventory.ledger', [
            'product' => $product->load(['variants', 'unit:id,short_name']),
            'movements' => $this->inventory->ledger($product->id, null, $branchId)->paginate(30)->withQueryString(),
            'byBranch' => $this->inventory->stockByBranch($product),
            'batches' => $product->tracks_batches
                ? $this->inventory->batches($product->id, $branchId)->get()
                : collect(),
            'branches' => Branch::query()->accessible()->ordered()->get(['id', 'name']),
            'selectedBranch' => $branchId,
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        ]);
    }

    /**
     * Batches and expiry (#34).
     *
     * Expired stock comes first and stays first. It is the only list on this
     * screen that costs money every day it is ignored.
     */
    public function expiry(Request $request): View
    {
        $branchId = $request->query('branch') !== null ? (int) $request->query('branch') : null;
        $window = (int) config('inventory.expiry_warning_days', 30);

        return view('app.inventory.expiry', [
            'expired' => $this->inventory->expiredBatches($branchId)->paginate(25, ['*'], 'expired')->withQueryString(),
            'expiring' => $this->inventory->expiringBatches($window, $branchId)->paginate(25, ['*'], 'expiring')->withQueryString(),
            'window' => $window,
            'branches' => Branch::query()->accessible()->ordered()->get(['id', 'name']),
            'selectedBranch' => $branchId,
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        ]);
    }

    /**
     * A manual correction (#31). The reason is required by the form request —
     * an unexplained stock change is how shrinkage hides.
     */
    public function adjust(StockAdjustmentRequest $request): RedirectResponse
    {
        $movement = $this->inventory->adjust(
            (int) $request->input('product_id'),
            (float) $request->input('quantity'),
            $request->string('reason')->toString(),
            $request->input('variant_id') !== null ? (int) $request->input('variant_id') : null,
            $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
            $request->input('notes'),
            [
                'batch_number' => $request->input('batch_number'),
                'expiry_date' => $request->input('expiry_date'),
                'batch_id' => $request->input('batch_id'),
            ],
        );

        return back()->with('success', sprintf(
            'Stock adjusted by %s — now %s.',
            $movement->signedQuantity(),
            rtrim(rtrim(number_format((float) $movement->balance_after, 4, '.', ','), '0'), '.'),
        ));
    }
}
