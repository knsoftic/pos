<?php

namespace App\Support;

use App\Services\ReportService;

/**
 * The catalogue of reports (#54, #55, #56).
 *
 * ================= WHY A REGISTRY AND NOT THIRTY CONTROLLERS =================
 * A report is four things: a question, who may ask it, which plan includes it,
 * and which filters make sense for it. Written as thirty controllers, those four
 * facts would be scattered across thirty files and the thirty-first report would
 * be written by copying the twenty-ninth — which is how a "sales by branch"
 * report ends up quietly ignoring the branch filter.
 *
 * Here every report declares itself in one place. The controller, the catalogue
 * screen, the filter bar and the export all read from this, so a report cannot
 * exist without an answer to all four questions.
 *
 * ================= THE TWO GATES, AGAIN =================
 * `feature` is what the PLAN includes; `permission` is what the PERSON may see.
 * They are not the same question and both are enforced (#187). The profit
 * reports carry `reports.view_profit` because a margin figure tells anyone who
 * reads it what the shop is really worth — see {@see PermissionRegistry}.
 *
 * ⚠️ Report keys are public: they appear in URLs and in export filenames. They
 * are also matched to a builder method in {@see ReportService},
 * so renaming one breaks both a bookmark and a method lookup. Add, don't rename.
 */
class ReportRegistry
{
    // ------------------------------------------------------------------ sales
    public const SALES_SUMMARY = 'sales.summary';

    public const SALES_BY_PRODUCT = 'sales.by_product';

    public const SALES_BY_CATEGORY = 'sales.by_category';

    public const SALES_BY_CUSTOMER = 'sales.by_customer';

    public const SALES_BY_EMPLOYEE = 'sales.by_employee';

    public const SALES_BY_BRANCH = 'sales.by_branch';

    public const SALES_BY_COUNTER = 'sales.by_counter';

    public const SALES_BY_PAYMENT = 'sales.by_payment';

    // ----------------------------------------------------------------- profit
    public const PROFIT_SUMMARY = 'profit.summary';

    public const PROFIT_BY_PRODUCT = 'profit.by_product';

    public const PROFIT_BY_CATEGORY = 'profit.by_category';

    public const PROFIT_BY_BRANCH = 'profit.by_branch';

    // -------------------------------------------------------------- inventory
    public const INVENTORY_STOCK = 'inventory.stock';

    public const INVENTORY_VALUATION = 'inventory.valuation';

    public const INVENTORY_LOW_STOCK = 'inventory.low_stock';

    public const INVENTORY_OUT_OF_STOCK = 'inventory.out_of_stock';

    public const INVENTORY_MOVEMENTS = 'inventory.movements';

    public const INVENTORY_ADJUSTMENTS = 'inventory.adjustments';

    public const INVENTORY_EXPIRY = 'inventory.expiry';

    public const INVENTORY_TRANSFERS = 'inventory.transfers';

    // -------------------------------------------------------------- purchases
    public const PURCHASES_SUMMARY = 'purchases.summary';

    public const PURCHASES_BY_SUPPLIER = 'purchases.by_supplier';

    public const PURCHASES_RETURNS = 'purchases.returns';

    public const PURCHASES_OUTSTANDING = 'purchases.outstanding';

    // -------------------------------------------------------------- customers
    public const CUSTOMERS_PURCHASES = 'customers.purchases';

    public const CUSTOMERS_OUTSTANDING = 'customers.outstanding';

    public const CUSTOMERS_LEDGER = 'customers.ledger';

    // --------------------------------------------------------------- expenses
    public const EXPENSES_SUMMARY = 'expenses.summary';

    public const EXPENSES_BY_CATEGORY = 'expenses.by_category';

    public const EXPENSES_BY_BRANCH = 'expenses.by_branch';

    /**
     * Every report, keyed by the code that appears in its URL.
     *
     * `filters` names the controls the filter bar renders for this report, and
     * nothing else. A report that lists what is on the shelf right now has no
     * date range — offering one would be a lie about what the numbers mean.
     *
     * @return array<string, array{name: string, group: string, description: string,
     *                             feature: string, permission: string, filters: list<string>,
     *                             dated?: bool, chart?: bool}>
     */
    public static function all(): array
    {
        return [
            // ================================================== sales (#54)
            self::SALES_SUMMARY => [
                'name' => 'Sales summary',
                'group' => 'sales',
                'description' => 'What was taken, day by day, month by month or year by year.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'interval', 'branch', 'employee'],
                'chart' => true,
            ],
            self::SALES_BY_PRODUCT => [
                'name' => 'Sales by product',
                'group' => 'sales',
                'description' => 'What is actually moving, and what is only taking up shelf.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch', 'category'],
            ],
            self::SALES_BY_CATEGORY => [
                'name' => 'Sales by category',
                'group' => 'sales',
                'description' => 'Which parts of the shop earn their space.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::SALES_BY_CUSTOMER => [
                'name' => 'Sales by customer',
                'group' => 'sales',
                'description' => 'Who buys the most — and who has stopped coming.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::SALES_BY_EMPLOYEE => [
                'name' => 'Sales by employee',
                'group' => 'sales',
                'description' => 'Who served how much, and at what average basket.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::SALES_BY_BRANCH => [
                'name' => 'Sales by branch',
                'group' => 'sales',
                'description' => 'Shop against shop, on the same days.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period'],
            ],
            self::SALES_BY_COUNTER => [
                'name' => 'Sales by till',
                'group' => 'sales',
                'description' => 'Which counter carries the queue.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::SALES_BY_PAYMENT => [
                'name' => 'Sales by payment method',
                'group' => 'sales',
                'description' => 'How the money arrived — and how much of it never did.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],

            // ================================================= profit (#54)
            self::PROFIT_SUMMARY => [
                'name' => 'Profit summary',
                'group' => 'profit',
                'description' => 'Revenue, cost and margin over time.',
                'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
                'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT,
                'filters' => ['period', 'interval', 'branch'],
                'chart' => true,
            ],
            self::PROFIT_BY_PRODUCT => [
                'name' => 'Profit by product',
                'group' => 'profit',
                'description' => 'What each line really earns, at the cost that applied when it sold.',
                'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
                'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT,
                'filters' => ['period', 'branch', 'category'],
            ],
            self::PROFIT_BY_CATEGORY => [
                'name' => 'Profit by category',
                'group' => 'profit',
                'description' => 'Where the margin comes from, not just the takings.',
                'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
                'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT,
                'filters' => ['period', 'branch'],
            ],
            self::PROFIT_BY_BRANCH => [
                'name' => 'Profit by branch',
                'group' => 'profit',
                'description' => 'Which shop is carrying which.',
                'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
                'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT,
                'filters' => ['period'],
            ],

            // ============================================== inventory (#54)
            self::INVENTORY_STOCK => [
                'name' => 'Stock on hand',
                'group' => 'inventory',
                'description' => 'What is on the shelf right now, branch by branch.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['branch', 'category'],
                'dated' => false,
            ],
            self::INVENTORY_VALUATION => [
                'name' => 'Stock valuation',
                'group' => 'inventory',
                'description' => 'What that stock is worth, at weighted average cost.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT,
                'filters' => ['branch', 'category'],
                'dated' => false,
            ],
            self::INVENTORY_LOW_STOCK => [
                'name' => 'Low stock',
                'group' => 'inventory',
                'description' => 'What needs reordering before it runs out.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['branch', 'category'],
                'dated' => false,
            ],
            self::INVENTORY_OUT_OF_STOCK => [
                'name' => 'Out of stock',
                'group' => 'inventory',
                'description' => 'What a customer asked for today and could not have.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['branch', 'category'],
                'dated' => false,
            ],
            self::INVENTORY_MOVEMENTS => [
                'name' => 'Stock movements',
                'group' => 'inventory',
                'description' => 'Every in and out, in the order it happened.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch', 'product'],
            ],
            self::INVENTORY_ADJUSTMENTS => [
                'name' => 'Stock adjustments',
                'group' => 'inventory',
                'description' => 'Every figure somebody changed by hand, and why.',
                'feature' => FeatureRegistry::REPORTS_ADVANCED,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::INVENTORY_EXPIRY => [
                'name' => 'Expiring stock',
                'group' => 'inventory',
                'description' => 'What is about to become worthless, soonest first.',
                'feature' => FeatureRegistry::INVENTORY_EXPIRY_TRACKING,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['branch'],
                'dated' => false,
            ],
            self::INVENTORY_TRANSFERS => [
                'name' => 'Stock transfers',
                'group' => 'inventory',
                'description' => 'What moved between branches, and what went missing on the way.',
                'feature' => FeatureRegistry::INVENTORY_TRANSFERS,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period'],
            ],

            // ============================================== purchases (#54)
            self::PURCHASES_SUMMARY => [
                'name' => 'Purchase summary',
                'group' => 'purchases',
                'description' => 'What was ordered and what actually arrived.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch', 'supplier'],
            ],
            self::PURCHASES_BY_SUPPLIER => [
                'name' => 'Purchases by supplier',
                'group' => 'purchases',
                'description' => 'Who the shop actually depends on.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::PURCHASES_RETURNS => [
                'name' => 'Purchase returns',
                'group' => 'purchases',
                'description' => 'What went back to the supplier, and why.',
                'feature' => FeatureRegistry::PURCHASES_RETURNS,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'supplier'],
            ],
            self::PURCHASES_OUTSTANDING => [
                'name' => 'Unpaid bills',
                'group' => 'purchases',
                'description' => 'What the shop owes on goods it has already received.',
                'feature' => FeatureRegistry::REPORTS_BASIC,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['supplier'],
                'dated' => false,
            ],

            // ============================================== customers (#54)
            self::CUSTOMERS_PURCHASES => [
                'name' => 'Customer purchases',
                'group' => 'customers',
                'description' => 'What each account has bought, and when they last did.',
                'feature' => FeatureRegistry::CUSTOMERS_MANAGEMENT,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::CUSTOMERS_OUTSTANDING => [
                'name' => 'Customer balances',
                'group' => 'customers',
                'description' => 'Who owes what, oldest debt first.',
                'feature' => FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => [],
                'dated' => false,
            ],
            self::CUSTOMERS_LEDGER => [
                'name' => 'Customer ledger',
                'group' => 'customers',
                'description' => 'One account, every entry, with the running balance.',
                'feature' => FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER,
                'permission' => PermissionRegistry::REPORTS_VIEW,
                'filters' => ['period', 'customer'],
            ],

            // =============================================== expenses (#54)
            self::EXPENSES_SUMMARY => [
                'name' => 'Expense summary',
                'group' => 'expenses',
                'description' => 'What the shop spent, over time.',
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
                'permission' => PermissionRegistry::EXPENSES_VIEW,
                'filters' => ['period', 'interval', 'branch'],
                'chart' => true,
            ],
            self::EXPENSES_BY_CATEGORY => [
                'name' => 'Expenses by category',
                'group' => 'expenses',
                'description' => 'Where it goes, biggest first.',
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
                'permission' => PermissionRegistry::EXPENSES_VIEW,
                'filters' => ['period', 'branch'],
            ],
            self::EXPENSES_BY_BRANCH => [
                'name' => 'Expenses by branch',
                'group' => 'expenses',
                'description' => 'What each shop costs to run.',
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
                'permission' => PermissionRegistry::EXPENSES_VIEW,
                'filters' => ['period'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'sales' => 'Sales',
            'profit' => 'Profit',
            'inventory' => 'Inventory',
            'purchases' => 'Purchases',
            'customers' => 'Customers',
            'expenses' => 'Expenses',
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array{name: string, group: string, description: string, feature: string, permission: string, filters: list<string>, dated?: bool, chart?: bool} */
    public static function definition(string $key): array
    {
        $all = self::all();

        abort_unless(isset($all[$key]), 404, 'No such report.');

        return $all[$key];
    }

    public static function name(string $key): string
    {
        return self::all()[$key]['name'] ?? $key;
    }

    /** Whether this report takes a date range at all (a shelf count does not). */
    public static function isDated(string $key): bool
    {
        return self::definition($key)['dated'] ?? true;
    }

    /** @return list<string> */
    public static function filters(string $key): array
    {
        return self::definition($key)['filters'];
    }

    /**
     * Reports of one group, keyed by code.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return array_filter(self::all(), fn (array $r) => $r['group'] === $group);
    }
}
