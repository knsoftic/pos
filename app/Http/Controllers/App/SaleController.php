<?php

namespace App\Http\Controllers\App;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sales, after the fact (#21, #23, #143, #145).
 *
 * ================= WHOSE SALES CAN YOU SEE? =================
 * `sales.view` shows a person their OWN sales; `sales.view_all` shows everyone's
 * in the branches they can reach. That split is the point of having two
 * permissions rather than one: a cashier needs to find the receipt they printed
 * five minutes ago, and does not need to see what the shop took all week.
 *
 * The filter is applied here, in the query, rather than by hiding rows in the
 * view — a hidden row is still a row that was fetched, paginated and counted.
 */
class SaleController extends Controller
{
    public function __construct(protected SaleService $sales) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $seesEverything = $user->can(PermissionRegistry::SALES_VIEW_ALL);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'branch' => (string) $request->query('branch', ''),
            'seller' => (string) $request->query('seller', ''),
            'payment' => (string) $request->query('payment', ''),
        ];

        $query = Sale::query()
            ->with(['customer:id,name', 'branch:id,name', 'seller:id,name', 'payments'])
            // Held sales belong at the till, not in the sales book: nothing has
            // happened yet, and listing them among real sales would make a day's
            // takings look bigger than it was.
            ->where('status', '!=', SaleStatus::Held)
            ->unless($seesEverything, fn (Builder $q) => $q->where('user_id', $user->id))
            ->when($filters['search'] !== '', fn (Builder $q) => $q->search($filters['search']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('sale_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('sale_date', '<=', $filters['to']))
            ->when($filters['branch'] !== '', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch']))
            ->when($filters['seller'] !== '', fn (Builder $q) => $q->where('user_id', (int) $filters['seller']))
            ->when($filters['payment'] === 'credit', fn (Builder $q) => $q->onCredit())
            ->newestFirst();

        // The headline figures are counted from the SAME filtered query, so what
        // the cards say and what the table shows can never disagree.
        $totals = $this->totals(clone $query);

        return view('app.sales.index', [
            'sales' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'totals' => $totals,
            'statuses' => collect(SaleStatus::options())->except(SaleStatus::Held->value),
            'branches' => Branch::query()->accessible()->ordered()->get(['id', 'name']),
            'sellers' => $seesEverything
                ? User::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'seesEverything' => $seesEverything,
            'canSeeProfit' => $user->can(PermissionRegistry::REPORTS_VIEW_PROFIT),
        ]);
    }

    public function show(Request $request, Sale $sale): View
    {
        $this->assertVisible($request, $sale);

        $sale->load([
            'items.product:id,name,sku',
            'items.variant:id,name',
            'payments',
            'customer',
            'branch:id,name',
            'counter:id,name',
            'seller:id,name',
        ]);

        return view('app.sales.show', [
            'sale' => $sale,
            'canSeeProfit' => $request->user()->can(PermissionRegistry::REPORTS_VIEW_PROFIT),
        ]);
    }

    /**
     * The printable receipt (#23, #144, #145).
     *
     * One view for all three widths. 80mm and 58mm are thermal rolls — the
     * difference is real estate, not layout — and A4 is what goes in an
     * envelope. Sharing the template means a change to what a receipt SAYS
     * cannot land on one width and miss another.
     */
    public function receipt(Request $request, Sale $sale): View
    {
        $this->assertVisible($request, $sale);

        $width = $request->query('width', config('pos.receipt.width', '80mm'));

        if (! in_array($width, ['58mm', '80mm', 'a4'], true)) {
            $width = '80mm';
        }

        // #143: a reprint is recorded. An invoice handed out twice is a thing an
        // auditor asks about, and "we do not know how many exist" is not an
        // answer. The first print is not a reprint, so it is not counted.
        if ($request->boolean('reprint')) {
            $this->sales->recordReprint($sale);
        }

        $sale->load(['items', 'payments', 'customer', 'branch', 'seller:id,name']);

        return view('app.sales.receipt', [
            'sale' => $sale,
            'width' => $width,
            'footer' => (string) config('pos.receipt.footer', ''),
            'showTax' => (bool) config('pos.receipt.show_tax_breakdown', true),
            'autoPrint' => $request->boolean('auto'),
        ]);
    }

    /** Void a sale (#198) — the record stays, the postings are reversed. */
    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why the sale is being voided — this goes on the record.',
        ]);

        $this->sales->void($sale, $validated['reason']);

        return back()->with('success', "Sale {$sale->invoice_no} voided. The stock is back and the record kept.");
    }

    // ------------------------------------------------------------- internals

    /**
     * A cashier without `sales.view_all` may only open their own sales.
     *
     * 404 rather than 403: someone else's invoice number is not their business,
     * and telling them "that exists but you cannot see it" is more than they
     * need to know.
     */
    protected function assertVisible(Request $request, Sale $sale): void
    {
        $user = $request->user();

        abort_if(
            ! $user->can(PermissionRegistry::SALES_VIEW_ALL) && $sale->user_id !== $user->id,
            404,
        );
    }

    /**
     * @return array{count: int, takings: float, on_credit: float, voided: int}
     */
    protected function totals(Builder $query): array
    {
        $sales = $query->reorder()->get(['id', 'status', 'total', 'due_amount']);
        $completed = $sales->where('status', SaleStatus::Completed);

        return [
            'count' => $completed->count(),
            'takings' => round((float) $completed->sum('total'), 2),
            'on_credit' => round((float) $completed->sum('due_amount'), 2),
            'voided' => $sales->where('status', SaleStatus::Voided)->count(),
        ];
    }
}
