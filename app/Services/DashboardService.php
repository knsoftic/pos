<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\FeatureRegistry;
use App\Support\Format;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the owner sees when they open the door (#12, #123, #124).
 *
 * ================= IT READS THE SAME DEFINITIONS AS EVERYTHING ELSE =========
 * Revenue here is {@see ProfitService}'s revenue, and the low-stock count is
 * {@see InventoryService}'s. Nothing on this screen computes a figure of its
 * own, because a dashboard that disagrees with the report it links to is worse
 * than no dashboard — the owner then has two numbers and no way to choose.
 *
 * ================= A LOCKED CARD IS NOT A ZERO =================
 * Every figure is gated by the permission that guards the screen it links to,
 * and an ungranted one comes back as NULL rather than 0. The view draws that as
 * a locked card. Showing a cashier "0" for gross profit would be a lie they
 * might repeat, and showing them the real number would be a leak (#52, #188).
 *
 * ================= AND AN EMPTY DASHBOARD IS A FAILURE =================
 * A shop that has not sold anything yet gets the quick actions and the setup
 * prompts, not six cards of em-dashes. The first ten minutes decide whether
 * somebody comes back.
 */
class DashboardService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected InventoryService $inventory,
        protected ProfitService $profit,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $user = auth('web')->user();
        $period = $this->period($filters);

        return [
            'period' => $period,
            'cards' => $this->cards($user, $period),
            'chart' => $this->chart($user, $period),
            'actions' => $this->quickActions($user),
            'activity' => $this->activity($user),
            'setup' => $this->setup($user),
        ];
    }

    // ------------------------------------------------------------- the cards

    /**
     * @param  array{from: string, to: string, days: int, label: string}  $period
     * @return array<int, array<string, mixed>>
     */
    protected function cards($user, array $period): array
    {
        $cards = [];

        $canSeeSales = $user?->can(PermissionRegistry::SALES_VIEW)
            && $this->features->enabled(FeatureRegistry::SALES_INVOICING);

        if ($canSeeSales) {
            $today = $this->salesTotals(now()->toDateString(), now()->toDateString());
            $range = $this->salesTotals($period['from'], $period['to']);

            $cards[] = [
                'key' => 'today',
                'label' => 'Today',
                'value' => $today['revenue'],
                'format' => 'money',
                'meta' => $today['count'].' '.str('sale')->plural($today['count']),
                'icon' => 'sales',
                'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                'href' => route('app.sales.index'),
            ];

            /*
            | Skipped when the chosen period IS today: the two cards would hold
            | the same figure under the same heading, and a dashboard that
            | repeats itself teaches people to stop reading it. (This happens
            | on the 1st of a month, where "today" and "this month" are one
            | range.)
            */
            $cards[] = $period['from'] === $period['to'] && Carbon::parse($period['to'])->isToday()
                ? null
                : [
                    'key' => 'period',
                    'label' => $period['label'],
                    'value' => $range['revenue'],
                    'format' => 'money',
                    'meta' => $range['count'] > 0
                        ? 'average '.Format::money($range['revenue'] / max(1, $range['count']))
                        : 'nothing sold yet',
                    'icon' => 'trending-up',
                    'tint' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10',
                    'href' => route('app.sales.index'),
                ];
        }

        $cards = array_values(array_filter($cards));

        // Profit is its own authority (#52) — the most sensitive number here.
        if ($user?->can(PermissionRegistry::REPORTS_VIEW_PROFIT)
            && $this->features->enabled(FeatureRegistry::ACCOUNTING_PROFIT_LOSS)) {
            $statement = $this->profit->statement($period);

            $cards[] = [
                'key' => 'profit',
                'label' => 'Gross profit',
                'value' => $statement['gross_profit'],
                'format' => 'money',
                'meta' => number_format($statement['gross_margin'], 1).'% margin',
                'icon' => 'reports',
                'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                'href' => route('app.reports.profit-loss'),
                'signed' => true,
            ];
        }

        if ($user?->can(PermissionRegistry::INVENTORY_VIEW)
            && $this->features->enabled(FeatureRegistry::INVENTORY_STOCK_TRACKING)) {
            $low = $this->inventory->lowStock()->count();

            $cards[] = [
                'key' => 'low_stock',
                'label' => 'Needs reordering',
                'value' => $low,
                'format' => 'number',
                'meta' => $low === 0 ? 'nothing is running out' : 'at or below the alert level',
                'icon' => 'inventory',
                'tint' => $low > 0
                    ? 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'
                    : 'text-slate-500 bg-slate-100 dark:bg-slate-800',
                'href' => route('app.reports.show', 'inventory.low_stock'),
            ];
        }

        if ($user?->can(PermissionRegistry::CUSTOMERS_VIEW)
            && $this->features->enabled(FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER)) {
            $owed = round((float) Customer::query()->where('balance', '>', 0)->sum('balance'), 2);

            $cards[] = [
                'key' => 'receivable',
                'label' => 'Owed to you',
                'value' => $owed,
                'format' => 'money',
                'meta' => 'on customer accounts',
                'icon' => 'customers',
                'tint' => 'text-sky-600 bg-sky-50 dark:bg-sky-500/10',
                'href' => route('app.reports.show', 'customers.outstanding'),
            ];
        }

        if ($user?->can(PermissionRegistry::EXPENSES_VIEW)
            && $this->features->enabled(FeatureRegistry::ACCOUNTING_EXPENSES)) {
            $spent = round((float) Expense::query()
                ->whereBetween('expense_date', [$period['from'], $period['to']])
                ->sum('amount'), 2);

            $cards[] = [
                'key' => 'expenses',
                'label' => 'Spent',
                'value' => $spent,
                'format' => 'money',
                'meta' => strtolower($period['label']),
                'icon' => 'expenses',
                'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                'href' => route('app.expenses.index'),
            ];
        }

        return $cards;
    }

    /**
     * Takings and count for a date range, defined exactly as the reports define
     * them: completed sales only, tax excluded, returns subtracted (#134).
     *
     * @return array{revenue: float, count: int}
     */
    protected function salesTotals(string $from, string $to): array
    {
        $sales = Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total - tax_total), 0) as v')
            ->first();

        $returned = SaleReturn::query()
            ->whereBetween('return_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(total - tax_total), 0) as v')
            ->value('v');

        return [
            'revenue' => round(round((float) ($sales->v ?? 0), 2) - round((float) $returned, 2), 2),
            'count' => (int) ($sales->c ?? 0),
        ];
    }

    // ------------------------------------------------------------- the chart

    /**
     * @param  array{from: string, to: string, days: int, label: string}  $period
     * @return array<string, mixed>|null
     */
    protected function chart($user, array $period): ?array
    {
        if (! $user?->can(PermissionRegistry::SALES_VIEW)
            || ! $this->features->enabled(FeatureRegistry::REPORTS_DASHBOARD_CHARTS)) {
            return null;
        }

        // Profit is a permission; takings alone are not. Somebody who may see
        // the sales book gets the revenue line and nothing more.
        $withProfit = $user->can(PermissionRegistry::REPORTS_VIEW_PROFIT)
            && $this->features->enabled(FeatureRegistry::ACCOUNTING_PROFIT_LOSS);

        if (! $withProfit) {
            $rows = collect();
            $cursor = Carbon::parse($period['from']);
            $end = Carbon::parse($period['to']);

            while ($cursor->lessThanOrEqualTo($end)) {
                $day = $cursor->toDateString();
                $rows->push(['date' => $day] + $this->salesTotals($day, $day));
                $cursor->addDay();
            }

            return [
                'labels' => $rows->pluck('date')->all(),
                'series' => [['name' => 'Takings', 'data' => $rows->pluck('revenue')->all()]],
            ];
        }

        $daily = $this->profit->daily($period);

        return [
            'labels' => $daily->pluck('date')->all(),
            'series' => [
                ['name' => 'Takings', 'data' => $daily->pluck('revenue')->all()],
                ['name' => 'Gross profit', 'data' => $daily->pluck('gross_profit')->all()],
            ],
        ];
    }

    // ---------------------------------------------------- quick actions (#123)

    /**
     * The four things somebody opens this screen to do.
     *
     * Filtered by permission AND plan, so nobody is offered a button that then
     * refuses them — an action you can see and cannot take is worse than one
     * that is not there.
     *
     * @return array<int, array<string, string>>
     */
    protected function quickActions($user): array
    {
        $candidates = [
            [
                'label' => 'New sale',
                'icon' => 'pos',
                'href' => 'app.pos.index',
                'permission' => PermissionRegistry::POS_OPERATE,
                'feature' => FeatureRegistry::POS_TERMINAL,
                'primary' => true,
            ],
            [
                'label' => 'Add a product',
                'icon' => 'products',
                'href' => 'app.products.create',
                'permission' => PermissionRegistry::PRODUCTS_CREATE,
                'feature' => null,
            ],
            [
                'label' => 'Record an expense',
                'icon' => 'expenses',
                'href' => 'app.expenses.create',
                'permission' => PermissionRegistry::EXPENSES_MANAGE,
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
            ],
            [
                'label' => 'New purchase',
                'icon' => 'purchases',
                'href' => 'app.purchases.create',
                'permission' => PermissionRegistry::PURCHASES_CREATE,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
        ];

        return collect($candidates)
            ->filter(fn (array $action) => $user?->can($action['permission'])
                && ($action['feature'] === null || $this->features->enabled($action['feature'])))
            ->values()
            ->all();
    }

    // -------------------------------------------------- recent activity (#124)

    /**
     * The last few things that happened, newest first.
     *
     * Each source is gated on its own permission, so this is not a back door
     * into a module somebody cannot open. Capped tightly: a feed is glanced at,
     * not read.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function activity($user): Collection
    {
        $items = collect();

        if ($user?->can(PermissionRegistry::SALES_VIEW)) {
            $sales = Sale::query()
                ->where('status', SaleStatus::Completed)
                ->with('customer:id,name')
                // `sales.view` alone means your own; `view_all` means everyone's
                // — narrowed in the query, exactly as the sales book does it.
                ->when(! $user->can(PermissionRegistry::SALES_VIEW_ALL),
                    fn (Builder $q) => $q->where('user_id', $user->id))
                ->latest('id')
                ->limit(5)
                ->get();

            foreach ($sales as $sale) {
                $items->push([
                    'at' => $sale->sold_at ?? $sale->created_at,
                    'icon' => 'sales',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                    'title' => $sale->invoice_no,
                    'body' => $sale->customerName().' · '.Format::money($sale->total, true),
                    'href' => route('app.sales.show', $sale),
                ]);
            }
        }

        if ($user?->can(PermissionRegistry::INVENTORY_VIEW)) {
            $movements = StockMovement::query()
                ->with('product:id,name')
                ->latest('id')
                ->limit(5)
                ->get();

            foreach ($movements as $movement) {
                $items->push([
                    'at' => $movement->created_at,
                    'icon' => 'inventory',
                    'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
                    'title' => $movement->product?->name ?? 'Stock',
                    'body' => $movement->type->label().' · '.$movement->signedQuantity(),
                    'href' => null,
                ]);
            }
        }

        if ($user?->can(PermissionRegistry::EXPENSES_VIEW)) {
            $expenses = Expense::query()->with('category:id,name')->latest('id')->limit(3)->get();

            foreach ($expenses as $expense) {
                $items->push([
                    'at' => $expense->created_at,
                    'icon' => 'expenses',
                    'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                    'title' => $expense->reference,
                    'body' => ($expense->category?->name ?? 'Expense').' · '.Format::money($expense->amount, true),
                    'href' => route('app.expenses.index'),
                ]);
            }
        }

        return $items->sortByDesc('at')->take(8)->values();
    }

    // --------------------------------------------------- getting started (#87)

    /**
     * What is still missing before the shop can actually trade.
     *
     * A brand-new tenant's dashboard should tell them what to do next, not show
     * them six cards of zeroes. Each step disappears the moment it is done, so
     * the list empties itself and then stops appearing altogether.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function setup($user): array
    {
        $steps = [];

        if ($user?->can(PermissionRegistry::PRODUCTS_VIEW)) {
            $products = Product::query()->count();

            $steps[] = [
                'label' => 'Add your first products',
                'done' => $products > 0,
                'href' => $user->can(PermissionRegistry::PRODUCTS_CREATE) ? route('app.products.create') : null,
                'meta' => $products > 0 ? $products.' in the catalogue' : 'nothing to sell yet',
            ];
        }

        if ($user?->can(PermissionRegistry::INVENTORY_VIEW) && $this->features->enabled(FeatureRegistry::INVENTORY_STOCK_TRACKING)) {
            $hasStock = StockMovement::query()->exists();

            $steps[] = [
                'label' => 'Put stock on the shelf',
                'done' => $hasStock,
                'href' => $user->can(PermissionRegistry::INVENTORY_ADJUST) ? route('app.inventory.index') : null,
                'meta' => $hasStock ? 'stock is moving' : 'record what you already have',
            ];
        }

        if ($user?->can(PermissionRegistry::EMPLOYEES_VIEW) && $this->features->enabled(FeatureRegistry::TEAM_MULTI_USER)) {
            $staff = User::query()->where('is_business_owner', false)->count();

            $steps[] = [
                'label' => 'Invite your team',
                'done' => $staff > 0,
                'href' => $user->can(PermissionRegistry::EMPLOYEES_MANAGE) ? route('app.employees.create') : null,
                'meta' => $staff > 0 ? $staff.' '.str('person')->plural($staff).' on the till' : 'so far it is just you',
            ];
        }

        if ($user?->can(PermissionRegistry::SALES_VIEW)) {
            $sold = Sale::query()->where('status', SaleStatus::Completed)->exists();

            $steps[] = [
                'label' => 'Ring up your first sale',
                'done' => $sold,
                'href' => $user->can(PermissionRegistry::POS_OPERATE) ? route('app.pos.index') : null,
                'meta' => $sold ? 'the till is in use' : 'open the till and try it',
            ];
        }

        // Once everything is done the list stops appearing at all — a checklist
        // of ticks is clutter on a dashboard somebody opens every morning.
        return collect($steps)->every(fn (array $step) => $step['done']) ? [] : $steps;
    }

    // ------------------------------------------------------------- internals

    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{from: string, to: string, days: int, label: string}
     */
    protected function period(array $filters): array
    {
        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth();
        $to = filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->startOfDay() : now()->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // A dashboard chart of 400 bars is a smear. The range is capped rather
        // than refused, so a wide date picker still answers something useful.
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => (int) $from->diffInDays($to) + 1,
            'label' => $this->label($from, $to),
        ];
    }

    protected function label(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->isToday() ? 'Today' : $from->format('d M');
        }

        if ($from->isSameDay(now()->startOfMonth()) && $to->isToday()) {
            return 'This month';
        }

        return 'Last '.((int) $from->diffInDays($to) + 1).' days';
    }

    /** Any expiring stock worth putting on the dashboard as a nudge. */
    public function expiringSoon(): int
    {
        if (! $this->features->enabled(FeatureRegistry::INVENTORY_EXPIRY_TRACKING)) {
            return 0;
        }

        return StockBatch::query()
            ->whereNotNull('expiry_date')
            ->where('quantity', '>', 0)
            ->where('expiry_date', '<=', now()->addDays((int) config('inventory.expiry_warning_days', 30))->toDateString())
            ->count();
    }
}
