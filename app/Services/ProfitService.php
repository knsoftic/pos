<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\OtherIncome;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit & Loss — the one place that decides what profit means (#45, #135, #183).
 *
 * Every profit figure in this system comes from here. That is the point: a
 * dashboard tile, a report and a P&L that each did their own arithmetic would
 * eventually disagree, and the owner would have no way to know which one to
 * believe.
 *
 *     Revenue − Cost of goods sold        = GROSS PROFIT
 *     Gross profit − Expenses + Other income = NET PROFIT
 *
 * ================= FOUR DECISIONS THAT SHAPE EVERY NUMBER =================
 *
 * 1. TAX IS NOT REVENUE. Sales tax is collected on someone else's behalf and
 *    handed over later; counting it as income would inflate revenue and margin
 *    by the tax rate, which is exactly the figure an owner uses to set prices.
 *    So revenue is `total − tax_total`.
 *
 * 2. COST IS THE COST THAT APPLIED WHEN IT SOLD (#52, #135). Every sale carries
 *    a snapshotted `cost_total`, taken from the shelf's WEIGHTED AVERAGE at the
 *    moment of sale. This service never recomputes cost from today's stock —
 *    if it did, last month's margin would change every time a delivery arrived
 *    at a different price, and a closed month would stop being closed.
 *
 * 3. ONLY RESTOCKED RETURNS REVERSE COGS. A returned item that was written off
 *    never came back to the shelf: the shop paid for it and no longer has it.
 *    Its revenue reverses, its cost does not. Reversing the whole return's cost
 *    would credit the business with inventory it does not own — and the loss on
 *    breakages would vanish from the accounts entirely.
 *
 * 4. STOCK PURCHASES ARE NOT EXPENSES. They reach profit through COGS when the
 *    goods sell. See {@see ExpenseService} for the other half of that rule.
 *
 * ================= WHY THE QUERIES LOOK LIKE THIS =================
 * Everything is an aggregate (#183). A P&L over a year would otherwise load
 * every sale of that year into memory to add up five columns. Nothing here
 * fetches a row it does not display.
 */
class ProfitService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
    ) {}

    /**
     * The whole statement, in the order it is read.
     *
     * @param  array{from?: string|null, to?: string|null, branch_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function statement(array $filters = []): array
    {
        $this->assertFeature();

        $period = $this->resolvePeriod($filters);
        $branchId = $this->resolveBranch($filters['branch_id'] ?? null);

        $revenue = $this->revenue($period, $branchId);
        $cogs = $this->costOfGoodsSold($period, $branchId);

        $grossProfit = round($revenue['net'] - $cogs['net'], 2);

        $expenses = $this->expenses($period, $branchId);
        $otherIncome = $this->otherIncome($period, $branchId);

        $netProfit = round($grossProfit - $expenses['total'] + $otherIncome['total'], 2);

        return [
            'from' => $period['from'],
            'to' => $period['to'],
            'days' => $period['days'],
            'branch' => $branchId !== null ? Branch::query()->find($branchId) : null,

            'revenue' => $revenue,
            'cogs' => $cogs,

            'gross_profit' => $grossProfit,
            // Margin is measured against NET revenue: "of every 100 the shop
            // actually kept, how much was profit?".
            'gross_margin' => $this->percentage($grossProfit, $revenue['net']),

            'expenses' => $expenses,
            'other_income' => $otherIncome,

            'net_profit' => $netProfit,
            'net_margin' => $this->percentage($netProfit, $revenue['net']),

            'cost_method' => $this->costMethodLabel(),
        ];
    }

    // ================================================================ revenue

    /**
     * What the shop earned, excluding tax it merely collected.
     *
     * @param  array{from: string, to: string, days: int}  $period
     * @return array{gross: float, returns: float, net: float, sales_count: int, returns_count: int}
     */
    public function revenue(array $period, ?int $branchId = null): array
    {
        $sales = $this->salesQuery($period, $branchId)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total - tax_total), 0) as v')
            ->first();

        $returns = $this->returnsQuery($period, $branchId)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total - tax_total), 0) as v')
            ->first();

        $gross = round((float) ($sales->v ?? 0), 2);
        $returned = round((float) ($returns->v ?? 0), 2);

        return [
            'gross' => $gross,
            'returns' => $returned,
            'net' => round($gross - $returned, 2),
            'sales_count' => (int) ($sales->c ?? 0),
            'returns_count' => (int) ($returns->c ?? 0),
        ];
    }

    // =================================================== cost of goods sold

    /**
     * What those goods cost the shop.
     *
     * @param  array{from: string, to: string, days: int}  $period
     * @return array{sold: float, restocked: float, written_off: float, net: float}
     */
    public function costOfGoodsSold(array $period, ?int $branchId = null): array
    {
        $sold = round((float) $this->salesQuery($period, $branchId)->sum('cost_total'), 2);

        // Only the lines that went back on the shelf. The join is expressed
        // through the relation so `sale_returns` keeps its tenant and branch
        // scopes — a raw join would quietly cross tenants.
        $restocked = round((float) SaleReturnItem::query()
            ->where('restock', true)
            ->whereHas('saleReturn', fn (Builder $q) => $this->applyReturnFilters($q, $period, $branchId))
            ->sum(DB::raw('quantity * unit_cost')), 2);

        // Cost that came back damaged: still the shop's, still spent. Shown so
        // that breakage has somewhere to be seen rather than silently sitting
        // inside COGS.
        $returnedCost = round((float) $this->returnsQuery($period, $branchId)->sum('cost_total'), 2);

        return [
            'sold' => $sold,
            'restocked' => $restocked,
            'written_off' => round($returnedCost - $restocked, 2),
            'net' => round($sold - $restocked, 2),
        ];
    }

    // =============================================== expenses & other income

    /**
     * @param  array{from: string, to: string, days: int}  $period
     * @return array{total: float, count: int, by_category: Collection<int, array<string, mixed>>}
     */
    public function expenses(array $period, ?int $branchId = null): array
    {
        $rows = Expense::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('expense_date', [$period['from'], $period['to']])
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id, COUNT(*) as c, COALESCE(SUM(amount), 0) as v')
            ->get();

        $total = round((float) $rows->sum('v'), 2);

        // One extra query for the names, rather than one per row.
        $names = ExpenseCategory::query()
            ->withTrashed()
            ->whereIn('id', $rows->pluck('expense_category_id'))
            ->pluck('name', 'id');

        $byCategory = $rows
            ->map(fn ($row) => [
                'id' => (int) $row->expense_category_id,
                'name' => $names[$row->expense_category_id] ?? 'Uncategorised',
                'count' => (int) $row->c,
                'amount' => round((float) $row->v, 2),
                'share' => $this->percentage((float) $row->v, $total),
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'total' => $total,
            'count' => (int) $rows->sum('c'),
            'by_category' => $byCategory,
        ];
    }

    /**
     * @param  array{from: string, to: string, days: int}  $period
     * @return array{total: float, count: int}
     */
    public function otherIncome(array $period, ?int $branchId = null): array
    {
        $row = OtherIncome::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('income_date', [$period['from'], $period['to']])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as v')
            ->first();

        return [
            'total' => round((float) ($row->v ?? 0), 2),
            'count' => (int) ($row->c ?? 0),
        ];
    }

    // ============================================================== trending

    /**
     * The same arithmetic, one row per day — for the chart above the statement.
     *
     * Four aggregate queries rather than one per day: a 90-day chart must not
     * be 360 round trips.
     *
     * @param  array{from: string, to: string, days: int}  $period
     * @return Collection<int, array<string, mixed>>
     */
    public function daily(array $period, ?int $branchId = null): Collection
    {
        $this->assertFeature();

        $sales = $this->salesQuery($period, $branchId)
            ->groupBy('sale_date')
            ->selectRaw('sale_date as d, COALESCE(SUM(total - tax_total), 0) as revenue, COALESCE(SUM(cost_total), 0) as cost')
            ->get()
            ->keyBy(fn ($r) => (string) $r->d);

        $returns = $this->returnsQuery($period, $branchId)
            ->groupBy('return_date')
            ->selectRaw('return_date as d, COALESCE(SUM(total - tax_total), 0) as revenue')
            ->get()
            ->keyBy(fn ($r) => (string) $r->d);

        $restocked = SaleReturnItem::query()
            ->where('sale_return_items.restock', true)
            ->whereHas('saleReturn', fn (Builder $q) => $this->applyReturnFilters($q, $period, $branchId))
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->groupBy('sale_returns.return_date')
            ->selectRaw('sale_returns.return_date as d, COALESCE(SUM(sale_return_items.quantity * sale_return_items.unit_cost), 0) as cost')
            ->get()
            ->keyBy(fn ($r) => (string) $r->d);

        $expenses = Expense::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('expense_date', [$period['from'], $period['to']])
            ->groupBy('expense_date')
            ->selectRaw('expense_date as d, COALESCE(SUM(amount), 0) as v')
            ->get()
            ->keyBy(fn ($r) => (string) $r->d);

        $income = OtherIncome::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('income_date', [$period['from'], $period['to']])
            ->groupBy('income_date')
            ->selectRaw('income_date as d, COALESCE(SUM(amount), 0) as v')
            ->get()
            ->keyBy(fn ($r) => (string) $r->d);

        $days = collect();
        $cursor = Carbon::parse($period['from']);
        $end = Carbon::parse($period['to']);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();

            $revenue = round((float) ($sales[$key]->revenue ?? 0) - (float) ($returns[$key]->revenue ?? 0), 2);
            $cogs = round((float) ($sales[$key]->cost ?? 0) - (float) ($restocked[$key]->cost ?? 0), 2);
            $spent = round((float) ($expenses[$key]->v ?? 0), 2);
            $earned = round((float) ($income[$key]->v ?? 0), 2);

            $days->push([
                'date' => $key,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => round($revenue - $cogs, 2),
                'expenses' => $spent,
                'other_income' => $earned,
                'net_profit' => round($revenue - $cogs - $spent + $earned, 2),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    // ------------------------------------------------------------- internals

    /**
     * Completed sales only. A held sale has not happened and a voided one has
     * been undone; either in the revenue line would report money the shop never
     * took.
     *
     * @param  array{from: string, to: string, days: int}  $period
     */
    protected function salesQuery(array $period, ?int $branchId): Builder
    {
        return Sale::query()
            ->where('status', SaleStatus::Completed)
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('sale_date', [$period['from'], $period['to']]);
    }

    /** @param  array{from: string, to: string, days: int}  $period */
    protected function returnsQuery(array $period, ?int $branchId): Builder
    {
        return $this->applyReturnFilters(SaleReturn::query(), $period, $branchId);
    }

    /**
     * A return is counted on the day it CAME BACK, not the day of the sale it
     * reverses. Otherwise a January refund would reopen December's closed
     * figures every time somebody changed their mind.
     *
     * @param  array{from: string, to: string, days: int}  $period
     */
    protected function applyReturnFilters(Builder $query, array $period, ?int $branchId): Builder
    {
        return $query
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('return_date', [$period['from'], $period['to']]);
    }

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{from: string, to: string, days: int}
     */
    protected function resolvePeriod(array $filters): array
    {
        $from = filled($filters['from'] ?? null)
            ? Carbon::parse($filters['from'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = filled($filters['to'] ?? null)
            ? Carbon::parse($filters['to'])->startOfDay()
            : Carbon::now()->startOfDay();

        // A backwards range is a slip, not a request for nothing.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // Carbon 3 returns a float here; the count of days in a period is
            // a whole number and gets compared as one.
            'days' => (int) $from->diffInDays($to) + 1,
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

    protected function percentage(float $part, float $whole): float
    {
        return abs($whole) < 0.005 ? 0.0 : round(($part / $whole) * 100, 2);
    }

    /**
     * Named on the statement so nobody has to guess (#135). A P&L whose costing
     * method is a mystery is a P&L nobody can check.
     */
    public function costMethodLabel(): string
    {
        return match ((string) config('inventory.valuation_method', 'average')) {
            'fifo' => 'First in, first out',
            default => 'Weighted average cost',
        };
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::ACCOUNTING_PROFIT_LOSS)) {
            throw new FeatureUnavailableException(FeatureRegistry::ACCOUNTING_PROFIT_LOSS, 'Profit & Loss');
        }
    }
}
