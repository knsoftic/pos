<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every report in the system (#54, #55, #134, #183).
 *
 * ================= ONE SHAPE FOR EVERY REPORT =================
 * `build()` returns the same structure whatever was asked for: columns, rows,
 * totals and a little metadata. That is what lets one screen, one CSV writer,
 * one spreadsheet writer and one PDF template serve thirty reports — and it
 * means a new report is a query, not a feature.
 *
 * ================= ACCURACY IS THE WHOLE POINT (#134) =================
 * A report that is merely approximately right is worse than none, because the
 * shop will act on it. Three rules are enforced in one place here rather than
 * remembered thirty times:
 *
 *   1. ONLY COMPLETED SALES COUNT. A held sale has not happened; a voided one
 *      has been undone. Either in a takings figure reports money the shop never
 *      had.
 *   2. RETURNS ARE SUBTRACTED, not ignored. Every sales and profit figure is
 *      net of what came back, and the returns are shown as their own column so
 *      the adjustment is visible rather than silent.
 *   3. TAX IS NOT REVENUE. Same rule as the P&L (see {@see ProfitService}), so
 *      the reports and the statement can never disagree.
 *
 * ================= WHY THESE QUERIES LOOK LIKE THIS (#183) =================
 * Everything is an aggregate, grouped in SQL. A year of sales by product must
 * not load a year of sale lines into memory to add up four columns, and a shop
 * on a slow connection is exactly the shop that will ask for a year.
 *
 * ================= ONE RULE ABOUT MONEY, AND ONE CAVEAT =================
 * THE RULE: round each figure to money BEFORE combining it with another, never
 * after. Rounding is not associative — 1043.4822 − 107.5258 is 935.96 rounded
 * once and 935.95 rounded first — so the summary reports do the arithmetic in
 * exactly the order {@see ProfitService} does. That is what lets the statement
 * and the reports show the same number, and it is also what makes the P&L add
 * up when a reader checks the subtraction themselves.
 *
 * THE CAVEAT: a BREAKDOWN by product, category or supplier is rounded per row,
 * so its column can differ from the document-level total by a penny or two. It
 * is not a bug and it must not be "fixed" by fiddling a row: the rows are each
 * correct, and a total that disagreed with the rows above it would be worse.
 * The document-level reports are the ones that reconcile to the statement.
 */
class ReportService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
    ) {}

    /**
     * Build one report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{key: string, name: string, description: string, group: string,
     *               columns: list<array<string, mixed>>, rows: Collection<int, array<string, mixed>>,
     *               totals: array<string, mixed>|null, meta: array<string, mixed>,
     *               chart: array<string, mixed>|null}
     */
    public function build(string $key, array $filters = []): array
    {
        $definition = ReportRegistry::definition($key);

        $this->assertFeature($definition['feature']);

        $context = $this->context($key, $filters);

        $method = 'build'.str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $key)));

        abort_unless(method_exists($this, $method), 500, "Report {$key} has no builder.");

        /** @var array{columns: list<array<string, mixed>>, rows: Collection<int, array<string, mixed>>, totals?: array<string, mixed>|null, chart?: array<string, mixed>|null} $result */
        $result = $this->{$method}($context);

        return [
            'key' => $key,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'group' => $definition['group'],
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'totals' => $result['totals'] ?? null,
            'chart' => $result['chart'] ?? null,
            'meta' => $context,
        ];
    }

    // =========================================================== sales (#54)

    /** @param array<string, mixed> $c */
    protected function buildSalesSummary(array $c): array
    {
        $sales = $this->salesQuery($c)
            ->groupByRaw($this->dateGroup('sales.sale_date', $c['interval']))
            ->selectRaw($this->dateGroup('sales.sale_date', $c['interval']).' as bucket')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.cost_total), 0) as cost, COALESCE(SUM(sales.tax_total), 0) as tax, COALESCE(SUM(sales.discount_total), 0) as discount')
            ->get()
            ->keyBy('bucket');

        $returns = $this->returnsQuery($c)
            ->groupByRaw($this->dateGroup('sale_returns.return_date', $c['interval']))
            ->selectRaw($this->dateGroup('sale_returns.return_date', $c['interval']).' as bucket')
            ->selectRaw('COALESCE(SUM(sale_returns.total - sale_returns.tax_total), 0) as returned')
            ->get()
            ->keyBy('bucket');

        $rows = $this->buckets($c)->map(function (string $bucket) use ($sales, $returns) {
            $gross = round((float) ($sales[$bucket]->revenue ?? 0), 2);
            $returned = round((float) ($returns[$bucket]->returned ?? 0), 2);
            $orders = (int) ($sales[$bucket]->orders ?? 0);

            return [
                'bucket' => $bucket,
                'orders' => $orders,
                'gross' => $gross,
                'returns' => $returned,
                'discount' => round((float) ($sales[$bucket]->discount ?? 0), 2),
                'tax' => round((float) ($sales[$bucket]->tax ?? 0), 2),
                'net' => round($gross - $returned, 2),
                'average' => $orders > 0 ? round($gross / $orders, 2) : 0.0,
            ];
        });

        return [
            'columns' => [
                ['key' => 'bucket', 'label' => $this->intervalLabel($c['interval']), 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'gross', 'label' => 'Takings', 'format' => 'money', 'align' => 'right'],
                ['key' => 'discount', 'label' => 'Discount', 'format' => 'money', 'align' => 'right'],
                ['key' => 'returns', 'label' => 'Returns', 'format' => 'money', 'align' => 'right'],
                ['key' => 'net', 'label' => 'Net', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'average', 'label' => 'Average sale', 'format' => 'money', 'align' => 'right'],
                ['key' => 'tax', 'label' => 'Tax collected', 'format' => 'money', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'gross', 'discount', 'returns', 'net', 'tax']) + [
                'bucket' => 'Total',
                'average' => $rows->sum('orders') > 0 ? round($rows->sum('gross') / $rows->sum('orders'), 2) : 0.0,
            ],
            'chart' => [
                'labels' => $rows->pluck('bucket')->all(),
                'series' => [
                    ['name' => 'Net takings', 'data' => $rows->pluck('net')->all()],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByProduct(array $c): array
    {
        $sold = $this->saleItemsQuery($c)
            ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
            ->selectRaw('sale_items.product_id, products.name as name, products.sku as sku')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as qty')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_items').'), 0) as revenue')
            ->get()
            ->keyBy('product_id');

        $returned = $this->returnItemsQuery($c)
            ->groupBy('sale_return_items.product_id')
            ->selectRaw('sale_return_items.product_id')
            ->selectRaw('COALESCE(SUM(sale_return_items.quantity), 0) as qty')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_return_items').'), 0) as revenue')
            ->get()
            ->keyBy('product_id');

        $rows = $sold->map(function ($row) use ($returned) {
            $back = $returned[$row->product_id] ?? null;

            return [
                'name' => $row->name,
                'sku' => $row->sku,
                'qty' => round((float) $row->qty - (float) ($back->qty ?? 0), 4),
                'returned_qty' => round((float) ($back->qty ?? 0), 4),
                'revenue' => round((float) $row->revenue - (float) ($back->revenue ?? 0), 2),
            ];
        })->sortByDesc('revenue')->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'returned_qty', 'label' => 'Returned', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'qty', 'label' => 'Net sold', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Net revenue', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['qty', 'returned_qty', 'revenue']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByCategory(array $c): array
    {
        $sold = $this->saleItemsQuery($c)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw('COALESCE(categories.name, "Uncategorised")')
            ->selectRaw('COALESCE(categories.name, "Uncategorised") as name')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as qty')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_items').'), 0) as revenue')
            ->get()
            ->keyBy('name');

        $returned = $this->returnItemsQuery($c)
            ->leftJoin('products', 'products.id', '=', 'sale_return_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw('COALESCE(categories.name, "Uncategorised")')
            ->selectRaw('COALESCE(categories.name, "Uncategorised") as name')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_return_items').'), 0) as revenue')
            ->get()
            ->keyBy('name');

        $rows = $sold->map(fn ($row) => [
            'name' => $row->name,
            'qty' => round((float) $row->qty, 4),
            'revenue' => round((float) $row->revenue - (float) ($returned[$row->name]->revenue ?? 0), 2),
        ])->sortByDesc('revenue')->values();

        $total = round($rows->sum('revenue'), 2);

        $rows = $rows->map(fn (array $row) => $row + [
            'share' => $total > 0 ? round(($row['revenue'] / $total) * 100, 1) : 0.0,
        ]);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Category', 'format' => 'text'],
                ['key' => 'qty', 'label' => 'Items sold', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Net revenue', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'share', 'label' => 'Share', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['qty', 'revenue']) + ['name' => 'Total', 'share' => 100.0],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByCustomer(array $c): array
    {
        $rows = $this->salesQuery($c)
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupByRaw('COALESCE(customers.name, "Walk-in customer")')
            ->selectRaw('COALESCE(customers.name, "Walk-in customer") as name')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.due_amount), 0) as owed, MAX(sales.sale_date) as last_seen')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'average' => (int) $row->orders > 0 ? round((float) $row->revenue / (int) $row->orders, 2) : 0.0,
                'owed' => round((float) $row->owed, 2),
                'last_seen' => (string) $row->last_seen,
            ])
            ->sortByDesc('revenue')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Customer', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'average', 'label' => 'Average sale', 'format' => 'money', 'align' => 'right'],
                ['key' => 'owed', 'label' => 'Charged to account', 'format' => 'money', 'align' => 'right'],
                ['key' => 'last_seen', 'label' => 'Last bought', 'format' => 'date'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'revenue', 'owed']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByEmployee(array $c): array
    {
        $rows = $this->salesQuery($c)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->groupByRaw('COALESCE(users.name, sales.user_name, "Unknown")')
            ->selectRaw('COALESCE(users.name, sales.user_name, "Unknown") as name')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.discount_total), 0) as discount')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'average' => (int) $row->orders > 0 ? round((float) $row->revenue / (int) $row->orders, 2) : 0.0,
                'discount' => round((float) $row->discount, 2),
            ])
            ->sortByDesc('revenue')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Served by', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'average', 'label' => 'Average sale', 'format' => 'money', 'align' => 'right'],
                // Worth watching: discount is the one lever a cashier has over
                // the till, and a person who gives away far more than everyone
                // else is worth a conversation either way.
                ['key' => 'discount', 'label' => 'Discount given', 'format' => 'money', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'revenue', 'discount']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByBranch(array $c): array
    {
        $sales = $this->salesQuery($c)
            ->groupBy('sales.branch_id')
            ->selectRaw('sales.branch_id, COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.cost_total), 0) as cost')
            ->get()
            ->keyBy('branch_id');

        $returns = $this->returnsQuery($c)
            ->groupBy('sale_returns.branch_id')
            ->selectRaw('sale_returns.branch_id, COALESCE(SUM(sale_returns.total - sale_returns.tax_total), 0) as returned')
            ->get()
            ->keyBy('branch_id');

        $rows = Branch::query()->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $branch) use ($sales, $returns) {
                $gross = round((float) ($sales[$branch->id]->revenue ?? 0), 2);
                $back = round((float) ($returns[$branch->id]->returned ?? 0), 2);

                return [
                    'name' => $branch->name,
                    'orders' => (int) ($sales[$branch->id]->orders ?? 0),
                    'gross' => $gross,
                    'returns' => $back,
                    'net' => round($gross - $back, 2),
                ];
            })
            ->sortByDesc('net')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'gross', 'label' => 'Takings', 'format' => 'money', 'align' => 'right'],
                ['key' => 'returns', 'label' => 'Returns', 'format' => 'money', 'align' => 'right'],
                ['key' => 'net', 'label' => 'Net', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'gross', 'returns', 'net']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByCounter(array $c): array
    {
        $rows = $this->salesQuery($c)
            ->leftJoin('pos_counters', 'pos_counters.id', '=', 'sales.pos_counter_id')
            ->groupByRaw('COALESCE(pos_counters.name, "No counter")')
            ->selectRaw('COALESCE(pos_counters.name, "No counter") as name')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'average' => (int) $row->orders > 0 ? round((float) $row->revenue / (int) $row->orders, 2) : 0.0,
            ])
            ->sortByDesc('revenue')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Till', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'average', 'label' => 'Average sale', 'format' => 'money', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'revenue']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildSalesByPayment(array $c): array
    {
        // Payments, not sales: a split-tender sale belongs to two methods, and
        // grouping by the sale would have to pick one and be wrong.
        $rows = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.business_id', $this->tenant->businessId())
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sale_date', [$c['from'], $c['to']])
            ->when($c['branch_id'] !== null, fn ($q) => $q->where('sales.branch_id', $c['branch_id']))
            ->when($this->branches->isRestricted(), fn ($q) => $q->whereIn('sales.branch_id', $this->branches->branchIds()))
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method as method, COUNT(*) as payments, COALESCE(SUM(sale_payments.amount), 0) as amount')
            ->get()
            ->map(fn ($row) => [
                'method' => str($row->method)->headline()->toString(),
                'payments' => (int) $row->payments,
                'amount' => round((float) $row->amount, 2),
            ])
            ->sortByDesc('amount')
            ->values();

        $total = round($rows->sum('amount'), 2);

        $rows = $rows->map(fn (array $row) => $row + [
            'share' => $total > 0 ? round(($row['amount'] / $total) * 100, 1) : 0.0,
        ]);

        return [
            'columns' => [
                ['key' => 'method', 'label' => 'Method', 'format' => 'text'],
                ['key' => 'payments', 'label' => 'Payments', 'format' => 'number', 'align' => 'right'],
                ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'share', 'label' => 'Share', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['payments', 'amount']) + ['method' => 'Total', 'share' => $rows->isEmpty() ? 0.0 : 100.0],
        ];
    }

    // ========================================================== profit (#54)

    /** @param array<string, mixed> $c */
    protected function buildProfitSummary(array $c): array
    {
        $sales = $this->salesQuery($c)
            ->groupByRaw($this->dateGroup('sales.sale_date', $c['interval']))
            ->selectRaw($this->dateGroup('sales.sale_date', $c['interval']).' as bucket')
            ->selectRaw('COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.cost_total), 0) as cost')
            ->get()
            ->keyBy('bucket');

        $returns = $this->returnsQuery($c)
            ->groupByRaw($this->dateGroup('sale_returns.return_date', $c['interval']))
            ->selectRaw($this->dateGroup('sale_returns.return_date', $c['interval']).' as bucket')
            ->selectRaw('COALESCE(SUM(sale_returns.total - sale_returns.tax_total), 0) as revenue')
            ->get()
            ->keyBy('bucket');

        // Only what went back on the shelf reverses cost — see ProfitService.
        $restocked = $this->restockedCostQuery($c)
            ->groupByRaw($this->dateGroup('sale_returns.return_date', $c['interval']))
            ->selectRaw($this->dateGroup('sale_returns.return_date', $c['interval']).' as bucket')
            ->selectRaw('COALESCE(SUM(ROUND(sale_return_items.quantity * sale_return_items.unit_cost, 4)), 0) as cost')
            ->get()
            ->keyBy('bucket');

        $rows = $this->buckets($c)->map(function (string $bucket) use ($sales, $returns, $restocked) {
            // ⚠️ Round each side to money BEFORE subtracting, exactly as
            // ProfitService does. Subtracting the raw 4-decimal figures and
            // rounding once gives a different penny, and then the statement
            // and this report disagree — see the note on money in this class.
            $revenue = round(
                round((float) ($sales[$bucket]->revenue ?? 0), 2) - round((float) ($returns[$bucket]->revenue ?? 0), 2),
                2,
            );
            $cost = round(
                round((float) ($sales[$bucket]->cost ?? 0), 2) - round((float) ($restocked[$bucket]->cost ?? 0), 2),
                2,
            );
            $profit = round($revenue - $cost, 2);

            return [
                'bucket' => $bucket,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ];
        });

        $revenue = round($rows->sum('revenue'), 2);
        $profit = round($rows->sum('profit'), 2);

        return [
            'columns' => [
                ['key' => 'bucket', 'label' => $this->intervalLabel($c['interval']), 'format' => 'text'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right'],
                ['key' => 'cost', 'label' => 'Cost of goods', 'format' => 'money', 'align' => 'right'],
                ['key' => 'profit', 'label' => 'Gross profit', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['revenue', 'cost', 'profit']) + [
                'bucket' => 'Total',
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ],
            'chart' => [
                'labels' => $rows->pluck('bucket')->all(),
                'series' => [
                    ['name' => 'Revenue', 'data' => $rows->pluck('revenue')->all()],
                    ['name' => 'Gross profit', 'data' => $rows->pluck('profit')->all()],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildProfitByProduct(array $c): array
    {
        $sold = $this->saleItemsQuery($c)
            ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
            ->selectRaw('sale_items.product_id, products.name as name, products.sku as sku')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as qty')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_items').'), 0) as revenue')
            ->selectRaw('COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost, 4)), 0) as cost')
            ->get()
            ->keyBy('product_id');

        $returned = $this->returnItemsQuery($c)
            ->groupBy('sale_return_items.product_id')
            ->selectRaw('sale_return_items.product_id')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_return_items').'), 0) as revenue')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_return_items.restock = 1 THEN ROUND(sale_return_items.quantity * sale_return_items.unit_cost, 4) ELSE 0 END), 0) as cost')
            ->selectRaw('COALESCE(SUM(sale_return_items.quantity), 0) as qty')
            ->get()
            ->keyBy('product_id');

        $rows = $sold->map(function ($row) use ($returned) {
            $back = $returned[$row->product_id] ?? null;

            $revenue = round((float) $row->revenue - (float) ($back->revenue ?? 0), 2);
            $cost = round((float) $row->cost - (float) ($back->cost ?? 0), 2);
            $profit = round($revenue - $cost, 2);

            return [
                'name' => $row->name,
                'sku' => $row->sku,
                'qty' => round((float) $row->qty - (float) ($back->qty ?? 0), 4),
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ];
        })->sortByDesc('profit')->values();

        $revenue = round($rows->sum('revenue'), 2);
        $profit = round($rows->sum('profit'), 2);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'qty', 'label' => 'Net sold', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right'],
                ['key' => 'cost', 'label' => 'Cost', 'format' => 'money', 'align' => 'right'],
                ['key' => 'profit', 'label' => 'Gross profit', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['qty', 'revenue', 'cost', 'profit']) + [
                'name' => 'Total',
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildProfitByCategory(array $c): array
    {
        $sold = $this->saleItemsQuery($c)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw('COALESCE(categories.name, "Uncategorised")')
            ->selectRaw('COALESCE(categories.name, "Uncategorised") as name')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_items').'), 0) as revenue')
            ->selectRaw('COALESCE(SUM(ROUND(sale_items.quantity * sale_items.unit_cost, 4)), 0) as cost')
            ->get()
            ->keyBy('name');

        $returned = $this->returnItemsQuery($c)
            ->leftJoin('products', 'products.id', '=', 'sale_return_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupByRaw('COALESCE(categories.name, "Uncategorised")')
            ->selectRaw('COALESCE(categories.name, "Uncategorised") as name')
            ->selectRaw('COALESCE(SUM('.$this->exTax('sale_return_items').'), 0) as revenue')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_return_items.restock = 1 THEN ROUND(sale_return_items.quantity * sale_return_items.unit_cost, 4) ELSE 0 END), 0) as cost')
            ->get()
            ->keyBy('name');

        $rows = $sold->map(function ($row) use ($returned) {
            $back = $returned[$row->name] ?? null;

            $revenue = round((float) $row->revenue - (float) ($back->revenue ?? 0), 2);
            $cost = round((float) $row->cost - (float) ($back->cost ?? 0), 2);
            $profit = round($revenue - $cost, 2);

            return [
                'name' => $row->name,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ];
        })->sortByDesc('profit')->values();

        $revenue = round($rows->sum('revenue'), 2);
        $profit = round($rows->sum('profit'), 2);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Category', 'format' => 'text'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right'],
                ['key' => 'cost', 'label' => 'Cost', 'format' => 'money', 'align' => 'right'],
                ['key' => 'profit', 'label' => 'Gross profit', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['revenue', 'cost', 'profit']) + [
                'name' => 'Total',
                'margin' => abs($revenue) > 0.005 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildProfitByBranch(array $c): array
    {
        $sales = $this->salesQuery($c)
            ->groupBy('sales.branch_id')
            ->selectRaw('sales.branch_id, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, COALESCE(SUM(sales.cost_total), 0) as cost')
            ->get()->keyBy('branch_id');

        $returns = $this->returnsQuery($c)
            ->groupBy('sale_returns.branch_id')
            ->selectRaw('sale_returns.branch_id, COALESCE(SUM(sale_returns.total - sale_returns.tax_total), 0) as revenue')
            ->get()->keyBy('branch_id');

        $restocked = $this->restockedCostQuery($c)
            ->groupBy('sale_returns.branch_id')
            ->selectRaw('sale_returns.branch_id, COALESCE(SUM(ROUND(sale_return_items.quantity * sale_return_items.unit_cost, 4)), 0) as cost')
            ->get()->keyBy('branch_id');

        $expenses = Expense::query()
            ->whereBetween('expense_date', [$c['from'], $c['to']])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(amount), 0) as amount')
            ->get()->keyBy('branch_id');

        $rows = Branch::query()->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $branch) use ($sales, $returns, $restocked, $expenses) {
                $revenue = round(round((float) ($sales[$branch->id]->revenue ?? 0), 2) - round((float) ($returns[$branch->id]->revenue ?? 0), 2), 2);
                $cost = round(round((float) ($sales[$branch->id]->cost ?? 0), 2) - round((float) ($restocked[$branch->id]->cost ?? 0), 2), 2);
                $spent = round((float) ($expenses[$branch->id]->amount ?? 0), 2);
                $gross = round($revenue - $cost, 2);

                return [
                    'name' => $branch->name,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'gross' => $gross,
                    'expenses' => $spent,
                    'net' => round($gross - $spent, 2),
                    'margin' => abs($revenue) > 0.005 ? round(($gross / $revenue) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('net')
            ->values();

        $revenue = round($rows->sum('revenue'), 2);
        $gross = round($rows->sum('gross'), 2);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'money', 'align' => 'right'],
                ['key' => 'cost', 'label' => 'Cost of goods', 'format' => 'money', 'align' => 'right'],
                ['key' => 'gross', 'label' => 'Gross profit', 'format' => 'money', 'align' => 'right'],
                ['key' => 'expenses', 'label' => 'Expenses', 'format' => 'money', 'align' => 'right'],
                ['key' => 'net', 'label' => 'Net profit', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'margin', 'label' => 'Gross margin', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['revenue', 'cost', 'gross', 'expenses', 'net']) + [
                'name' => 'Total',
                'margin' => abs($revenue) > 0.005 ? round(($gross / $revenue) * 100, 1) : 0.0,
            ],
        ];
    }

    // ======================================================= inventory (#54)

    /** @param array<string, mixed> $c */
    protected function buildInventoryStock(array $c): array
    {
        $rows = $this->stockQuery($c)
            ->get()
            ->map(fn (Stock $s) => [
                'name' => $this->shelfName($s),
                'sku' => $s->product?->sku,
                'branch' => $s->branch?->name,
                'quantity' => round((float) $s->quantity, 4),
                'alert' => $s->alertQuantity(),
                'status' => $s->isOutOfStock() ? 'Out of stock' : ($s->isLow() ? 'Low' : 'In stock'),
            ])
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'quantity', 'label' => 'On hand', 'format' => 'quantity', 'align' => 'right', 'emphasis' => true],
                ['key' => 'alert', 'label' => 'Alert at', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ],
            'rows' => $rows,
            'totals' => ['name' => 'Total', 'quantity' => round($rows->sum('quantity'), 4)],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryValuation(array $c): array
    {
        $rows = $this->stockQuery($c)
            ->get()
            ->map(fn (Stock $s) => [
                'name' => $this->shelfName($s),
                'sku' => $s->product?->sku,
                'branch' => $s->branch?->name,
                'quantity' => round((float) $s->quantity, 4),
                'cost' => round((float) $s->average_cost, 4),
                'value' => $s->value(),
            ])
            ->sortByDesc('value')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'quantity', 'label' => 'On hand', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'cost', 'label' => 'Average cost', 'format' => 'money', 'align' => 'right'],
                ['key' => 'value', 'label' => 'Value', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => ['name' => 'Total', 'quantity' => round($rows->sum('quantity'), 4), 'value' => round($rows->sum('value'), 2)],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryLowStock(array $c): array
    {
        $rows = $this->stockQuery($c)
            ->get()
            ->filter(fn (Stock $s) => $s->isLow() && ! $s->isOutOfStock())
            ->map(fn (Stock $s) => [
                'name' => $this->shelfName($s),
                'sku' => $s->product?->sku,
                'branch' => $s->branch?->name,
                'quantity' => round((float) $s->quantity, 4),
                'alert' => $s->alertQuantity(),
                'shortfall' => round(max(0, (float) ($s->alertQuantity() ?? 0) - (float) $s->quantity), 4),
            ])
            ->sortByDesc('shortfall')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'quantity', 'label' => 'On hand', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'alert', 'label' => 'Alert at', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'shortfall', 'label' => 'Short by', 'format' => 'quantity', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => ['name' => 'Total', 'shortfall' => round($rows->sum('shortfall'), 4)],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryOutOfStock(array $c): array
    {
        $rows = $this->stockQuery($c)
            ->get()
            ->filter(fn (Stock $s) => $s->isOutOfStock())
            ->map(fn (Stock $s) => [
                'name' => $this->shelfName($s),
                'sku' => $s->product?->sku,
                'branch' => $s->branch?->name,
                'quantity' => round((float) $s->quantity, 4),
                'last_movement' => $s->last_movement_at?->toDateString(),
            ])
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                // Negative is possible where the shop allows overselling, and is
                // shown rather than clamped — an oversold shelf is a real problem.
                ['key' => 'quantity', 'label' => 'On hand', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'last_movement', 'label' => 'Last moved', 'format' => 'date'],
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryMovements(array $c): array
    {
        $rows = StockMovement::query()
            ->with(['product:id,name,sku', 'branch:id,name', 'user:id,name'])
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('branch_id', $c['branch_id']))
            ->when($c['product_id'] !== null, fn (Builder $q) => $q->where('product_id', $c['product_id']))
            ->whereBetween(DB::raw('DATE(created_at)'), [$c['from'], $c['to']])
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(fn (StockMovement $m) => [
                'date' => $m->created_at?->format('Y-m-d H:i'),
                'name' => $m->product?->name,
                'branch' => $m->branch?->name,
                'type' => $m->type->label(),
                'quantity' => round((float) $m->quantity, 4),
                'balance' => round((float) $m->balance_after, 4),
                'reason' => $m->reason,
            ]);

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'When', 'format' => 'text'],
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'type', 'label' => 'Type', 'format' => 'text'],
                ['key' => 'quantity', 'label' => 'Change', 'format' => 'quantity', 'align' => 'right', 'signed' => true],
                ['key' => 'balance', 'label' => 'Balance after', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'reason', 'label' => 'Reason', 'format' => 'text'],
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryAdjustments(array $c): array
    {
        $rows = StockMovement::query()
            ->with(['product:id,name,sku', 'branch:id,name', 'user:id,name'])
            ->whereIn('type', [StockMovementType::Adjustment, StockMovementType::StockTake])
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('branch_id', $c['branch_id']))
            ->whereBetween(DB::raw('DATE(created_at)'), [$c['from'], $c['to']])
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(fn (StockMovement $m) => [
                'date' => $m->created_at?->format('Y-m-d H:i'),
                'name' => $m->product?->name,
                'branch' => $m->branch?->name,
                'type' => $m->type->label(),
                'quantity' => round((float) $m->quantity, 4),
                // A reason is required on every adjustment (#31) — this column
                // is the reason that rule exists.
                'reason' => $m->reason,
                'user' => $m->user?->name,
            ]);

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'When', 'format' => 'text'],
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'type', 'label' => 'Type', 'format' => 'text'],
                ['key' => 'quantity', 'label' => 'Change', 'format' => 'quantity', 'align' => 'right', 'signed' => true],
                ['key' => 'reason', 'label' => 'Reason', 'format' => 'text'],
                ['key' => 'user', 'label' => 'By', 'format' => 'text'],
            ],
            'rows' => $rows,
            'totals' => ['date' => 'Net change', 'quantity' => round($rows->sum('quantity'), 4)],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryExpiry(array $c): array
    {
        $days = (int) config('inventory.expiry_warning_days', 30);

        $rows = StockBatch::query()
            ->with(['product:id,name,sku', 'branch:id,name'])
            ->whereNotNull('expiry_date')
            ->where('quantity', '>', 0)
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('branch_id', $c['branch_id']))
            ->where('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expiry_date')
            ->get()
            ->map(function (StockBatch $b) {
                $daysLeft = (int) now()->startOfDay()->diffInDays(Carbon::parse($b->expiry_date)->startOfDay(), false);

                return [
                    'name' => $b->product?->name,
                    'batch' => $b->batch_number,
                    'branch' => $b->branch?->name,
                    'expiry' => Carbon::parse($b->expiry_date)->toDateString(),
                    'days_left' => $daysLeft,
                    'quantity' => round((float) $b->quantity, 4),
                    'value' => round((float) $b->quantity * (float) $b->unit_cost, 2),
                    'status' => $daysLeft < 0 ? 'Expired' : 'Expiring',
                ];
            });

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
                ['key' => 'batch', 'label' => 'Batch', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'expiry', 'label' => 'Expires', 'format' => 'date'],
                ['key' => 'days_left', 'label' => 'Days left', 'format' => 'number', 'align' => 'right'],
                ['key' => 'quantity', 'label' => 'Quantity', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'value', 'label' => 'Value at risk', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ],
            'rows' => $rows,
            'totals' => ['name' => 'Total', 'quantity' => round($rows->sum('quantity'), 4), 'value' => round($rows->sum('value'), 2)],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildInventoryTransfers(array $c): array
    {
        $rows = StockTransfer::query()
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'items'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$c['from'], $c['to']])
            ->orderByDesc('id')
            ->get()
            ->map(function (StockTransfer $t) {
                $sent = round((float) $t->items->sum('quantity_sent'), 4);
                $received = round((float) $t->items->sum('quantity_received'), 4);

                return [
                    'reference' => $t->reference,
                    'from' => $t->fromBranch?->name,
                    'to' => $t->toBranch?->name,
                    'status' => $t->status->label(),
                    'sent' => $sent,
                    'received' => $received,
                    // The number this report exists for: what left and never
                    // arrived. It is never reconciled away (#32).
                    'shortfall' => round($sent - $received, 4),
                    'date' => $t->created_at?->toDateString(),
                ];
            });

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Transfer', 'format' => 'text'],
                ['key' => 'date', 'label' => 'Raised', 'format' => 'date'],
                ['key' => 'from', 'label' => 'From', 'format' => 'text'],
                ['key' => 'to', 'label' => 'To', 'format' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
                ['key' => 'sent', 'label' => 'Sent', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'received', 'label' => 'Received', 'format' => 'quantity', 'align' => 'right'],
                ['key' => 'shortfall', 'label' => 'Lost on the way', 'format' => 'quantity', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['sent', 'received', 'shortfall']) + ['reference' => 'Total'],
        ];
    }

    // ======================================================= purchases (#54)

    /** @param array<string, mixed> $c */
    protected function buildPurchasesSummary(array $c): array
    {
        $rows = Purchase::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('branch_id', $c['branch_id']))
            ->when($c['supplier_id'] !== null, fn (Builder $q) => $q->where('supplier_id', $c['supplier_id']))
            ->whereBetween('order_date', [$c['from'], $c['to']])
            ->orderByDesc('order_date')
            ->get()
            ->map(fn (Purchase $p) => [
                'reference' => $p->reference,
                'date' => $p->order_date?->toDateString(),
                'supplier' => $p->supplier?->name,
                'branch' => $p->branch?->name,
                'status' => $p->status->label(),
                'total' => round((float) $p->total, 2),
                'paid' => round((float) $p->paid_amount, 2),
                'due' => round((float) $p->total - (float) $p->paid_amount, 2),
            ]);

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Order', 'format' => 'text'],
                ['key' => 'date', 'label' => 'Ordered', 'format' => 'date'],
                ['key' => 'supplier', 'label' => 'Supplier', 'format' => 'text'],
                ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
                ['key' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'paid', 'label' => 'Paid', 'format' => 'money', 'align' => 'right'],
                ['key' => 'due', 'label' => 'Still owed', 'format' => 'money', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['total', 'paid', 'due']) + ['reference' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildPurchasesBySupplier(array $c): array
    {
        $rows = Purchase::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', '!=', PurchaseStatus::Cancelled->value)
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('purchases.branch_id', $c['branch_id']))
            ->whereBetween('purchases.order_date', [$c['from'], $c['to']])
            ->groupByRaw('COALESCE(suppliers.name, "No supplier")')
            ->selectRaw('COALESCE(suppliers.name, "No supplier") as name')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(purchases.total), 0) as total, COALESCE(SUM(purchases.paid_amount), 0) as paid')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'total' => round((float) $row->total, 2),
                'paid' => round((float) $row->paid, 2),
                'due' => round((float) $row->total - (float) $row->paid, 2),
            ])
            ->sortByDesc('total')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Supplier', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Orders', 'format' => 'number', 'align' => 'right'],
                ['key' => 'total', 'label' => 'Ordered value', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'paid', 'label' => 'Paid', 'format' => 'money', 'align' => 'right'],
                ['key' => 'due', 'label' => 'Still owed', 'format' => 'money', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'total', 'paid', 'due']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildPurchasesReturns(array $c): array
    {
        $rows = PurchaseReturn::query()
            ->with(['supplier:id,name', 'purchase:id,reference'])
            ->when($c['supplier_id'] !== null, fn (Builder $q) => $q->where('supplier_id', $c['supplier_id']))
            ->whereBetween('return_date', [$c['from'], $c['to']])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseReturn $r) => [
                'reference' => $r->reference,
                'date' => $r->return_date?->toDateString(),
                'supplier' => $r->supplier?->name,
                'against' => $r->purchase?->reference,
                'reason' => $r->reason,
                'total' => round((float) $r->total, 2),
            ]);

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Return', 'format' => 'text'],
                ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
                ['key' => 'supplier', 'label' => 'Supplier', 'format' => 'text'],
                ['key' => 'against', 'label' => 'Against', 'format' => 'text'],
                ['key' => 'reason', 'label' => 'Reason', 'format' => 'text'],
                ['key' => 'total', 'label' => 'Value', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['total']) + ['reference' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildPurchasesOutstanding(array $c): array
    {
        $rows = Purchase::query()
            ->with(['supplier:id,name'])
            ->whereIn('status', [PurchaseStatus::Partial, PurchaseStatus::Received])
            ->whereColumn('paid_amount', '<', 'total')
            ->when($c['supplier_id'] !== null, fn (Builder $q) => $q->where('supplier_id', $c['supplier_id']))
            ->orderBy('order_date')
            ->get()
            ->map(function (Purchase $p) {
                $due = round((float) $p->total - (float) $p->paid_amount, 2);

                return [
                    'reference' => $p->reference,
                    'date' => $p->order_date?->toDateString(),
                    'supplier' => $p->supplier?->name,
                    'total' => round((float) $p->total, 2),
                    'paid' => round((float) $p->paid_amount, 2),
                    'due' => $due,
                    // How long it has been sitting there — the only column that
                    // turns a list of bills into a decision about which to pay.
                    'age_days' => $p->order_date ? (int) $p->order_date->startOfDay()->diffInDays(now()->startOfDay()) : 0,
                ];
            });

        return [
            'columns' => [
                ['key' => 'reference', 'label' => 'Order', 'format' => 'text'],
                ['key' => 'date', 'label' => 'Ordered', 'format' => 'date'],
                ['key' => 'supplier', 'label' => 'Supplier', 'format' => 'text'],
                ['key' => 'total', 'label' => 'Total', 'format' => 'money', 'align' => 'right'],
                ['key' => 'paid', 'label' => 'Paid', 'format' => 'money', 'align' => 'right'],
                ['key' => 'due', 'label' => 'Still owed', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'age_days', 'label' => 'Days old', 'format' => 'number', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['total', 'paid', 'due']) + ['reference' => 'Total'],
        ];
    }

    // ======================================================= customers (#54)

    /** @param array<string, mixed> $c */
    protected function buildCustomersPurchases(array $c): array
    {
        $rows = $this->salesQuery($c)
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->selectRaw('customers.name as name, customers.phone as phone')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(sales.total - sales.tax_total), 0) as revenue, MAX(sales.sale_date) as last_seen')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'phone' => $row->phone,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'average' => (int) $row->orders > 0 ? round((float) $row->revenue / (int) $row->orders, 2) : 0.0,
                'last_seen' => (string) $row->last_seen,
            ])
            ->sortByDesc('revenue')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Customer', 'format' => 'text'],
                ['key' => 'phone', 'label' => 'Phone', 'format' => 'text'],
                ['key' => 'orders', 'label' => 'Sales', 'format' => 'number', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Spent', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'average', 'label' => 'Average', 'format' => 'money', 'align' => 'right'],
                ['key' => 'last_seen', 'label' => 'Last bought', 'format' => 'date'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['orders', 'revenue']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildCustomersOutstanding(array $c): array
    {
        $rows = Customer::query()
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->get(['id', 'name', 'phone', 'balance', 'credit_limit'])
            ->map(fn (Customer $customer) => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => round((float) $customer->balance, 2),
                'limit' => $customer->credit_limit === null ? null : round((float) $customer->credit_limit, 2),
                // The one that matters: somebody already past their ceiling is a
                // decision, not a statistic.
                'over_limit' => $customer->credit_limit !== null && (float) $customer->balance > (float) $customer->credit_limit ? 'Yes' : '',
            ]);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Customer', 'format' => 'text'],
                ['key' => 'phone', 'label' => 'Phone', 'format' => 'text'],
                ['key' => 'balance', 'label' => 'Owes', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'limit', 'label' => 'Credit limit', 'format' => 'money', 'align' => 'right'],
                ['key' => 'over_limit', 'label' => 'Over limit', 'format' => 'text'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['balance']) + ['name' => 'Total'],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildCustomersLedger(array $c): array
    {
        // Without a customer this report would be every account's entries in one
        // list, with a running balance that belongs to nobody.
        abort_if($c['customer_id'] === null, 422, 'Choose a customer to see their ledger.');

        $rows = LedgerEntry::query()
            ->where('party_type', (new Customer)->getMorphClass())
            ->where('party_id', $c['customer_id'])
            ->whereBetween('entry_date', [$c['from'], $c['to']])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->map(fn (LedgerEntry $e) => [
                'date' => $e->entry_date?->toDateString(),
                'type' => $e->type->label(),
                'reference' => $e->reference_no,
                'description' => $e->description,
                'debit' => round((float) $e->debit, 2),
                'credit' => round((float) $e->credit, 2),
                'balance' => round((float) $e->balance_after, 2),
            ]);

        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
                ['key' => 'type', 'label' => 'Type', 'format' => 'text'],
                ['key' => 'reference', 'label' => 'Reference', 'format' => 'text'],
                ['key' => 'description', 'label' => 'Description', 'format' => 'text'],
                ['key' => 'debit', 'label' => 'Charged', 'format' => 'money', 'align' => 'right'],
                ['key' => 'credit', 'label' => 'Paid / credited', 'format' => 'money', 'align' => 'right'],
                ['key' => 'balance', 'label' => 'Balance', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['debit', 'credit']) + [
                'date' => 'Total',
                // The closing balance is the LAST row's, not a sum — a running
                // balance summed would be meaningless.
                'balance' => $rows->isEmpty() ? 0.0 : (float) $rows->last()['balance'],
            ],
        ];
    }

    // ======================================================== expenses (#54)

    /** @param array<string, mixed> $c */
    protected function buildExpensesSummary(array $c): array
    {
        $spent = $this->expensesQuery($c)
            ->groupByRaw($this->dateGroup('expenses.expense_date', $c['interval']))
            ->selectRaw($this->dateGroup('expenses.expense_date', $c['interval']).' as bucket')
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(expenses.amount), 0) as amount')
            ->get()
            ->keyBy('bucket');

        $rows = $this->buckets($c)->map(fn (string $bucket) => [
            'bucket' => $bucket,
            'entries' => (int) ($spent[$bucket]->entries ?? 0),
            'amount' => round((float) ($spent[$bucket]->amount ?? 0), 2),
        ]);

        return [
            'columns' => [
                ['key' => 'bucket', 'label' => $this->intervalLabel($c['interval']), 'format' => 'text'],
                ['key' => 'entries', 'label' => 'Entries', 'format' => 'number', 'align' => 'right'],
                ['key' => 'amount', 'label' => 'Spent', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['entries', 'amount']) + ['bucket' => 'Total'],
            'chart' => [
                'labels' => $rows->pluck('bucket')->all(),
                'series' => [['name' => 'Spent', 'data' => $rows->pluck('amount')->all()]],
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildExpensesByCategory(array $c): array
    {
        $rows = $this->expensesQuery($c)
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->groupByRaw('COALESCE(expense_categories.name, "Uncategorised")')
            ->selectRaw('COALESCE(expense_categories.name, "Uncategorised") as name')
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(expenses.amount), 0) as amount')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'entries' => (int) $row->entries,
                'amount' => round((float) $row->amount, 2),
            ])
            ->sortByDesc('amount')
            ->values();

        $total = round($rows->sum('amount'), 2);

        $rows = $rows->map(fn (array $row) => $row + [
            'share' => $total > 0 ? round(($row['amount'] / $total) * 100, 1) : 0.0,
        ]);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Category', 'format' => 'text'],
                ['key' => 'entries', 'label' => 'Entries', 'format' => 'number', 'align' => 'right'],
                ['key' => 'amount', 'label' => 'Spent', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
                ['key' => 'share', 'label' => 'Share', 'format' => 'percent', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['entries', 'amount']) + ['name' => 'Total', 'share' => $rows->isEmpty() ? 0.0 : 100.0],
        ];
    }

    /** @param array<string, mixed> $c */
    protected function buildExpensesByBranch(array $c): array
    {
        $spent = $this->expensesQuery($c)
            ->groupBy('expenses.branch_id')
            ->selectRaw('expenses.branch_id, COUNT(*) as entries, COALESCE(SUM(expenses.amount), 0) as amount')
            ->get()
            ->keyBy('branch_id');

        $rows = Branch::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $branch) => [
                'name' => $branch->name,
                'entries' => (int) ($spent[$branch->id]->entries ?? 0),
                'amount' => round((float) ($spent[$branch->id]->amount ?? 0), 2),
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Branch', 'format' => 'text'],
                ['key' => 'entries', 'label' => 'Entries', 'format' => 'number', 'align' => 'right'],
                ['key' => 'amount', 'label' => 'Spent', 'format' => 'money', 'align' => 'right', 'emphasis' => true],
            ],
            'rows' => $rows,
            'totals' => $this->sumRows($rows, ['entries', 'amount']) + ['name' => 'Total'],
        ];
    }

    // ============================================== the shared query pieces

    /**
     * Completed sales in the period. THE base query for #134: a held sale has
     * not happened and a voided one has been undone.
     *
     * @param  array<string, mixed>  $c
     */
    protected function salesQuery(array $c): Builder
    {
        return Sale::query()
            ->where('sales.status', SaleStatus::Completed)
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('sales.branch_id', $c['branch_id']))
            ->when($c['employee_id'] !== null, fn (Builder $q) => $q->where('sales.user_id', $c['employee_id']))
            ->whereBetween('sales.sale_date', [$c['from'], $c['to']]);
    }

    /** @param  array<string, mixed>  $c */
    protected function returnsQuery(array $c): Builder
    {
        return SaleReturn::query()
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('sale_returns.branch_id', $c['branch_id']))
            ->whereBetween('sale_returns.return_date', [$c['from'], $c['to']]);
    }

    /**
     * Sale LINES of completed sales, joined to their product.
     *
     * @param  array<string, mixed>  $c
     */
    protected function saleItemsQuery(array $c): Builder
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('sales.branch_id', $c['branch_id']))
            ->when($c['employee_id'] !== null, fn (Builder $q) => $q->where('sales.user_id', $c['employee_id']))
            ->when($c['category_id'] !== null, fn (Builder $q) => $q->where('products.category_id', $c['category_id']))
            ->when($this->branches->isRestricted(), fn (Builder $q) => $q->whereIn('sales.branch_id', $this->branches->branchIds()))
            ->whereBetween('sales.sale_date', [$c['from'], $c['to']]);
    }

    /** @param  array<string, mixed>  $c */
    protected function returnItemsQuery(array $c): Builder
    {
        return SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('sale_returns.branch_id', $c['branch_id']))
            ->when($this->branches->isRestricted(), fn (Builder $q) => $q->whereIn('sale_returns.branch_id', $this->branches->branchIds()))
            ->whereBetween('sale_returns.return_date', [$c['from'], $c['to']]);
    }

    /** Restocked return lines only — the ones that actually reverse cost. */
    protected function restockedCostQuery(array $c): Builder
    {
        return $this->returnItemsQuery($c)->where('sale_return_items.restock', true);
    }

    /** @param  array<string, mixed>  $c */
    protected function expensesQuery(array $c): Builder
    {
        return Expense::query()
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('expenses.branch_id', $c['branch_id']))
            ->whereBetween('expenses.expense_date', [$c['from'], $c['to']]);
    }

    /** @param  array<string, mixed>  $c */
    protected function stockQuery(array $c): Builder
    {
        return Stock::query()
            ->with(['product:id,name,sku,alert_quantity,category_id', 'variant:id,name,alert_quantity', 'branch:id,name'])
            ->when($c['branch_id'] !== null, fn (Builder $q) => $q->where('stocks.branch_id', $c['branch_id']))
            ->when($c['category_id'] !== null, fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('category_id', $c['category_id'])))
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->orderBy('products.name')
            ->select('stocks.*');
    }

    /**
     * A shelf's name, variant included.
     *
     * ⚠️ Without the variant, a shop selling three sizes of one shirt gets
     * three identical rows with different numbers and no way to tell which is
     * which — which is exactly the row somebody would reorder against.
     */
    protected function shelfName(Stock $stock): string
    {
        $name = (string) ($stock->product?->name ?? 'Unknown product');

        return $stock->variant?->name ? $name.' — '.$stock->variant->name : $name;
    }

    /**
     * A line's cost, rounded the way the SALE rounded it.
     *
     * ⚠️ The ROUND matters. `sales.cost_total` is the sum of each line's cost
     * rounded to 4 decimals (see SaleService::totalise), so a report that
     * multiplied at full precision and rounded once at the end would drift from
     * the P&L by a penny or two — and a penny of disagreement between two
     * screens is enough to make an owner distrust both.
     */
    protected function lineCost(string $table): string
    {
        return "ROUND({$table}.quantity * {$table}.unit_cost, 4)";
    }

    /**
     * A line's revenue with the tax taken back out.
     *
     * `line_total` is what the customer paid for the line — discount applied,
     * tax included. The P&L excludes tax from revenue, so the reports must too,
     * or a shop would find its "sales by product" adding up to more than its
     * profit statement said it earned.
     */
    protected function exTax(string $table): string
    {
        return "{$table}.line_total / (1 + ({$table}.tax_rate / 100))";
    }

    /** MySQL grouping expression for a day / month / year bucket. */
    protected function dateGroup(string $column, string $interval): string
    {
        return match ($interval) {
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            'year' => "DATE_FORMAT({$column}, '%Y')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }

    protected function intervalLabel(string $interval): string
    {
        return match ($interval) {
            'month' => 'Month',
            'year' => 'Year',
            default => 'Day',
        };
    }

    /**
     * Every bucket in the period, including the empty ones.
     *
     * A quiet Tuesday is information: dropping it would make a chart of takings
     * look like an unbroken run of trading and hide the day the shop was shut.
     *
     * @param  array<string, mixed>  $c
     * @return Collection<int, string>
     */
    protected function buckets(array $c): Collection
    {
        $out = collect();
        $cursor = Carbon::parse($c['from']);
        $end = Carbon::parse($c['to']);

        while ($cursor->lessThanOrEqualTo($end)) {
            $out->push(match ($c['interval']) {
                'month' => $cursor->format('Y-m'),
                'year' => $cursor->format('Y'),
                default => $cursor->toDateString(),
            });

            match ($c['interval']) {
                'month' => $cursor->addMonthNoOverflow()->startOfMonth(),
                'year' => $cursor->addYear()->startOfYear(),
                default => $cursor->addDay(),
            };
        }

        return $out->unique()->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    protected function sumRows(Collection $rows, array $keys): array
    {
        $totals = [];

        foreach ($keys as $key) {
            $totals[$key] = round((float) $rows->sum($key), 4);
        }

        return $totals;
    }

    /**
     * Everything a builder needs, resolved once and validated once (#55).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function context(string $key, array $filters): array
    {
        $allowed = ReportRegistry::filters($key);

        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth();
        $to = filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->startOfDay() : now()->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $branchId = $this->resolveBranch($filters['branch_id'] ?? null);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => (int) $from->diffInDays($to) + 1,
            'dated' => ReportRegistry::isDated($key),
            'interval' => in_array($filters['interval'] ?? 'day', ['day', 'month', 'year'], true)
                ? ($filters['interval'] ?? 'day')
                : 'day',
            'branch_id' => $branchId,
            'branch' => $branchId !== null ? Branch::query()->find($branchId) : null,
            // A filter a report did not ask for is ignored rather than silently
            // applied — the filter bar and the query must agree about what is
            // being answered.
            'employee_id' => in_array('employee', $allowed, true) ? $this->intOrNull($filters['employee_id'] ?? null) : null,
            'customer_id' => in_array('customer', $allowed, true) ? $this->intOrNull($filters['customer_id'] ?? null) : null,
            'supplier_id' => in_array('supplier', $allowed, true) ? $this->intOrNull($filters['supplier_id'] ?? null) : null,
            'category_id' => in_array('category', $allowed, true) ? $this->intOrNull($filters['category_id'] ?? null) : null,
            'product_id' => in_array('product', $allowed, true) ? $this->intOrNull($filters['product_id'] ?? null) : null,
        ];
    }

    protected function resolveBranch(mixed $branchId): ?int
    {
        if (blank($branchId)) {
            return null;
        }

        $branchId = (int) $branchId;

        abort_unless($this->branches->allows($branchId), 403, 'That branch is outside your access.');

        return $branchId;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    protected function assertFeature(string $code): void
    {
        if (! $this->features->enabled($code)) {
            throw new FeatureUnavailableException($code, FeatureRegistry::all()[$code]['name'] ?? 'This report');
        }
    }
}
