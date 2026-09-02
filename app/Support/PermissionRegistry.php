<?php

namespace App\Support;

use App\Services\PermissionService;

/**
 * The canonical catalogue of module/action permissions (#51, #52).
 *
 * Same split as {@see FeatureRegistry} and {@see LimitRegistry}: this class owns
 * the VOCABULARY, the `roles` / `role_permissions` tables own who gets what. No
 * role is defined here — the business owner builds their own roles (#51), so
 * "Cashier can void invoices" is tenant data, never a constant in the code.
 *
 * CODE SHAPE — `module.action`, always a verb the user would recognise. A
 * permission answers "may this person do X", never "does this plan include X":
 * that second question belongs to {@see FeatureRegistry}, and the two are
 * checked together by {@see PermissionService} (#187).
 *
 * `feature` — the subscription feature this permission depends on, if any.
 * Layer 1 of the three-layer check: no matter what the role says, a permission
 * whose feature is not in the plan is denied, and it is hidden from the role
 * editor entirely so an owner never ticks a box that cannot work.
 *
 * `sensitive` — the permissions #52 calls out: seeing cost and profit, voiding
 * or deleting financial records, refunds, exports, and anything that changes who
 * else can do what. Flagged so the role editor can warn before they are handed
 * out, and so an audit can list them in one query.
 */
final class PermissionRegistry
{
    // ------------------------------------------------------------- overview
    public const DASHBOARD_VIEW = 'dashboard.view';

    // ------------------------------------------------------------------ POS
    public const POS_OPERATE = 'pos.operate';

    public const POS_DISCOUNT = 'pos.discount';

    public const POS_OPEN_REGISTER = 'pos.open_register';

    public const POS_CLOSE_REGISTER = 'pos.close_register';

    // ---------------------------------------------------------------- sales
    public const SALES_VIEW = 'sales.view';

    public const SALES_VIEW_ALL = 'sales.view_all';

    public const SALES_CREATE = 'sales.create';

    public const SALES_UPDATE = 'sales.update';

    public const SALES_VOID = 'sales.void';

    public const SALES_RETURN = 'sales.return';

    public const SALES_PAYMENT_RECORD = 'sales.payment_record';

    public const SALES_PAYMENT_REFUND = 'sales.payment_refund';

    // -------------------------------------------------------------- catalog
    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_CREATE = 'products.create';

    public const PRODUCTS_UPDATE = 'products.update';

    public const PRODUCTS_DELETE = 'products.delete';

    public const PRODUCTS_VIEW_COST = 'products.view_cost';

    public const PRODUCTS_IMPORT = 'products.import';

    public const CATALOG_MANAGE = 'catalog.manage';

    // ------------------------------------------------------------ inventory
    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_TRANSFER = 'inventory.transfer';

    // NOT `inventory.stock_take` — that string is a FEATURE code, and one string
    // meaning both "the plan includes stock takes" and "this person may run one"
    // would be a very quiet disaster. A test asserts the two vocabularies stay
    // disjoint.
    public const INVENTORY_STOCK_TAKE = 'inventory.stock_count';

    // ----------------------------------------------------------- purchasing
    public const PURCHASES_VIEW = 'purchases.view';

    public const PURCHASES_CREATE = 'purchases.create';

    public const PURCHASES_UPDATE = 'purchases.update';

    public const PURCHASES_VOID = 'purchases.void';

    public const PURCHASES_RETURN = 'purchases.return';

    public const SUPPLIERS_VIEW = 'suppliers.view';

    public const SUPPLIERS_MANAGE = 'suppliers.manage';

    public const SUPPLIERS_LEDGER = 'suppliers.ledger';

    // --------------------------------------------------------------- people
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const CUSTOMERS_LEDGER = 'customers.ledger';

    public const EMPLOYEES_VIEW = 'employees.view';

    public const EMPLOYEES_MANAGE = 'employees.manage';

    public const ROLES_MANAGE = 'roles.manage';

    // ---------------------------------------------------------------- money
    public const EXPENSES_VIEW = 'expenses.view';

    public const EXPENSES_MANAGE = 'expenses.manage';

    // -------------------------------------------------------------- reports
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_VIEW_PROFIT = 'reports.view_profit';

    public const REPORTS_EXPORT = 'reports.export';

    // ------------------------------------------------------- administration
    public const BRANCHES_VIEW = 'branches.view';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const POS_COUNTERS_MANAGE = 'pos_counters.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const BILLING_MANAGE = 'billing.manage';

    public const AUDIT_VIEW = 'audit.view';

    /**
     * Every permission, in display order.
     *
     * @return array<string, array{name: string, group: string, description: string, sensitive: bool, feature: string|null}>
     */
    public static function all(): array
    {
        return [
            // ------------------------------------------------------ overview
            self::DASHBOARD_VIEW => [
                'name' => 'View dashboard',
                'group' => 'overview',
                'description' => 'See the business dashboard and its summary figures.',
                'sensitive' => false,
                'feature' => null,
            ],

            // ----------------------------------------------------------- POS
            self::POS_OPERATE => [
                'name' => 'Operate the POS',
                'group' => 'pos',
                'description' => 'Open the selling screen and ring up sales.',
                'sensitive' => false,
                'feature' => FeatureRegistry::POS_TERMINAL,
            ],
            self::POS_DISCOUNT => [
                'name' => 'Apply discounts',
                'group' => 'pos',
                'description' => 'Discount a line or a whole sale, up to the cap set on the employee.',
                'sensitive' => false,
                'feature' => FeatureRegistry::SALES_DISCOUNTS,
            ],
            self::POS_OPEN_REGISTER => [
                'name' => 'Open the register',
                'group' => 'pos',
                'description' => 'Start a cash session with an opening float.',
                'sensitive' => false,
                'feature' => FeatureRegistry::ACCOUNTING_CASH_REGISTER,
            ],
            self::POS_CLOSE_REGISTER => [
                'name' => 'Close the register',
                'group' => 'pos',
                'description' => 'Count the drawer and close the cash session.',
                'sensitive' => true,
                'feature' => FeatureRegistry::ACCOUNTING_CASH_REGISTER,
            ],

            // --------------------------------------------------------- sales
            self::SALES_VIEW => [
                'name' => 'View own sales',
                'group' => 'sales',
                'description' => 'See the invoices this employee rang up.',
                'sensitive' => false,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_VIEW_ALL => [
                'name' => "View everyone's sales",
                'group' => 'sales',
                'description' => 'See every invoice in the branches this employee can reach.',
                'sensitive' => false,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_CREATE => [
                'name' => 'Create sales',
                'group' => 'sales',
                'description' => 'Issue a new invoice.',
                'sensitive' => false,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_UPDATE => [
                'name' => 'Edit sales',
                'group' => 'sales',
                'description' => 'Correct an invoice after it was issued.',
                'sensitive' => true,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_VOID => [
                'name' => 'Void / cancel invoices',
                'group' => 'sales',
                'description' => 'Cancel an invoice. The record stays, it is never deleted (#198).',
                'sensitive' => true,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_RETURN => [
                'name' => 'Process returns',
                'group' => 'sales',
                'description' => 'Take goods back and refund or credit the customer.',
                'sensitive' => true,
                'feature' => FeatureRegistry::SALES_RETURNS,
            ],
            self::SALES_PAYMENT_RECORD => [
                'name' => 'Record payments',
                'group' => 'sales',
                'description' => 'Take a payment against an invoice or a customer balance.',
                'sensitive' => false,
                'feature' => FeatureRegistry::SALES_INVOICING,
            ],
            self::SALES_PAYMENT_REFUND => [
                'name' => 'Refund payments',
                'group' => 'sales',
                'description' => 'Pay money back out.',
                'sensitive' => true,
                'feature' => FeatureRegistry::SALES_RETURNS,
            ],

            // ------------------------------------------------------- catalog
            self::PRODUCTS_VIEW => [
                'name' => 'View products',
                'group' => 'catalog',
                'description' => 'Browse the catalogue and selling prices.',
                'sensitive' => false,
                'feature' => null,
            ],
            self::PRODUCTS_CREATE => [
                'name' => 'Add products',
                'group' => 'catalog',
                'description' => 'Create new catalogue items.',
                'sensitive' => false,
                'feature' => null,
            ],
            self::PRODUCTS_UPDATE => [
                'name' => 'Edit products',
                'group' => 'catalog',
                'description' => 'Change product details and prices.',
                'sensitive' => false,
                'feature' => null,
            ],
            self::PRODUCTS_DELETE => [
                'name' => 'Archive products',
                'group' => 'catalog',
                'description' => 'Retire a product. Items with history are archived, not deleted (#104).',
                'sensitive' => true,
                'feature' => null,
            ],
            self::PRODUCTS_VIEW_COST => [
                'name' => 'See cost prices',
                'group' => 'catalog',
                'description' => 'See what the business paid — purchase cost and margin.',
                'sensitive' => true,
                'feature' => null,
            ],
            self::PRODUCTS_IMPORT => [
                'name' => 'Bulk import products',
                'group' => 'catalog',
                'description' => 'Load the catalogue from a spreadsheet.',
                'sensitive' => true,
                'feature' => FeatureRegistry::CATALOG_IMPORT,
            ],
            self::CATALOG_MANAGE => [
                'name' => 'Manage categories, brands & units',
                'group' => 'catalog',
                'description' => 'Maintain the lists products are filed under.',
                'sensitive' => false,
                'feature' => null,
            ],

            // ----------------------------------------------------- inventory
            self::INVENTORY_VIEW => [
                'name' => 'View stock',
                'group' => 'inventory',
                'description' => 'See stock on hand and movement history.',
                'sensitive' => false,
                'feature' => FeatureRegistry::INVENTORY_STOCK_TRACKING,
            ],
            self::INVENTORY_ADJUST => [
                'name' => 'Adjust stock',
                'group' => 'inventory',
                'description' => 'Write stock up or down with a reason. Changes what the business owns.',
                'sensitive' => true,
                'feature' => FeatureRegistry::INVENTORY_ADJUSTMENTS,
            ],
            self::INVENTORY_TRANSFER => [
                'name' => 'Transfer stock',
                'group' => 'inventory',
                'description' => 'Move stock between branches or warehouses.',
                'sensitive' => false,
                'feature' => FeatureRegistry::INVENTORY_TRANSFERS,
            ],
            self::INVENTORY_STOCK_TAKE => [
                'name' => 'Run a stock take',
                'group' => 'inventory',
                'description' => 'Count the shelves and reconcile against the system.',
                'sensitive' => true,
                'feature' => FeatureRegistry::INVENTORY_STOCK_TAKE,
            ],

            // ---------------------------------------------------- purchasing
            self::PURCHASES_VIEW => [
                'name' => 'View purchases',
                'group' => 'purchasing',
                'description' => 'See purchase orders and supplier bills.',
                'sensitive' => false,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::PURCHASES_CREATE => [
                'name' => 'Create purchases',
                'group' => 'purchasing',
                'description' => 'Raise a purchase and receive stock against it.',
                'sensitive' => false,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::PURCHASES_UPDATE => [
                'name' => 'Edit purchases',
                'group' => 'purchasing',
                'description' => 'Correct a purchase after it was entered.',
                'sensitive' => true,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::PURCHASES_VOID => [
                'name' => 'Void purchases',
                'group' => 'purchasing',
                'description' => 'Cancel a purchase. The record stays (#198).',
                'sensitive' => true,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::PURCHASES_RETURN => [
                'name' => 'Return to supplier',
                'group' => 'purchasing',
                'description' => 'Send goods back and adjust the supplier balance.',
                'sensitive' => true,
                'feature' => FeatureRegistry::PURCHASES_RETURNS,
            ],
            self::SUPPLIERS_VIEW => [
                'name' => 'View suppliers',
                'group' => 'purchasing',
                'description' => 'See supplier records and contact details.',
                'sensitive' => false,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::SUPPLIERS_MANAGE => [
                'name' => 'Manage suppliers',
                'group' => 'purchasing',
                'description' => 'Add and edit suppliers.',
                'sensitive' => false,
                'feature' => FeatureRegistry::PURCHASES_ORDERS,
            ],
            self::SUPPLIERS_LEDGER => [
                'name' => 'Supplier ledger & payments',
                'group' => 'purchasing',
                'description' => 'See what is owed to suppliers and settle it.',
                'sensitive' => true,
                'feature' => FeatureRegistry::PURCHASES_SUPPLIER_LEDGER,
            ],

            // -------------------------------------------------------- people
            self::CUSTOMERS_VIEW => [
                'name' => 'View customers',
                'group' => 'people',
                'description' => 'See customer records.',
                'sensitive' => false,
                'feature' => FeatureRegistry::CUSTOMERS_MANAGEMENT,
            ],
            self::CUSTOMERS_MANAGE => [
                'name' => 'Manage customers',
                'group' => 'people',
                'description' => 'Add and edit customers.',
                'sensitive' => false,
                'feature' => FeatureRegistry::CUSTOMERS_MANAGEMENT,
            ],
            self::CUSTOMERS_LEDGER => [
                'name' => 'Customer ledger & receipts',
                'group' => 'people',
                'description' => 'See what customers owe and settle it.',
                'sensitive' => true,
                'feature' => FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER,
            ],
            self::EMPLOYEES_VIEW => [
                'name' => 'View employees',
                'group' => 'people',
                'description' => 'See the staff list.',
                'sensitive' => false,
                'feature' => FeatureRegistry::TEAM_MULTI_USER,
            ],
            self::EMPLOYEES_MANAGE => [
                'name' => 'Manage employees',
                'group' => 'people',
                'description' => 'Create logins, assign roles, branches and tills, deactivate staff.',
                'sensitive' => true,
                'feature' => FeatureRegistry::TEAM_MULTI_USER,
            ],
            self::ROLES_MANAGE => [
                'name' => 'Manage roles & permissions',
                'group' => 'people',
                'description' => 'Decide what everyone else is allowed to do.',
                'sensitive' => true,
                'feature' => FeatureRegistry::TEAM_ROLES,
            ],

            // --------------------------------------------------------- money
            self::EXPENSES_VIEW => [
                'name' => 'View expenses',
                'group' => 'money',
                'description' => 'See recorded business expenses.',
                'sensitive' => false,
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
            ],
            self::EXPENSES_MANAGE => [
                'name' => 'Manage expenses',
                'group' => 'money',
                'description' => 'Record and edit expenses.',
                'sensitive' => true,
                'feature' => FeatureRegistry::ACCOUNTING_EXPENSES,
            ],

            // ------------------------------------------------------- reports
            self::REPORTS_VIEW => [
                'name' => 'View reports',
                'group' => 'reports',
                'description' => 'Open the sales and stock reports.',
                'sensitive' => false,
                'feature' => FeatureRegistry::REPORTS_BASIC,
            ],
            self::REPORTS_VIEW_PROFIT => [
                'name' => 'See profit & margin',
                'group' => 'reports',
                'description' => 'See cost-based figures: gross profit, margin, profit & loss.',
                'sensitive' => true,
                'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
            ],
            self::REPORTS_EXPORT => [
                'name' => 'Export reports',
                'group' => 'reports',
                'description' => 'Download report data — it leaves the system and outlives the account.',
                'sensitive' => true,
                /*
                | ⚠️ Tied to `reports.basic`, NOT to a file format. This
                | permission answers "may this person take figures out of the
                | system?", which has nothing to do with whether the plan sells
                | PDF or Excel. Hanging it on `reports.export_pdf` made CSV —
                | which every plan gets — unreachable for a shop without the
                | PDF add-on, including its owner, since an owner outranks
                | roles but never the subscription.
                */
                'feature' => FeatureRegistry::REPORTS_BASIC,
            ],

            // ------------------------------------------------ administration
            self::BRANCHES_VIEW => [
                'name' => 'View branches',
                'group' => 'admin',
                'description' => 'See the list of shop locations.',
                'sensitive' => false,
                'feature' => null,
            ],
            self::BRANCHES_MANAGE => [
                'name' => 'Manage branches',
                'group' => 'admin',
                'description' => 'Add, edit and close branches.',
                'sensitive' => true,
                // Deliberately NOT gated on multi-branch: every business has one
                // branch and must be able to rename it. Creating a SECOND one is
                // what needs the feature, and that is enforced where it happens
                // ({@see \App\Services\BranchService::create()}).
                'feature' => null,
            ],
            self::POS_COUNTERS_MANAGE => [
                'name' => 'Manage POS counters',
                'group' => 'admin',
                'description' => 'Register the tills in each branch.',
                'sensitive' => false,
                'feature' => FeatureRegistry::POS_TERMINAL,
            ],
            self::SETTINGS_MANAGE => [
                'name' => 'Manage settings',
                'group' => 'admin',
                'description' => 'Change business settings, receipt layout and tax rules.',
                'sensitive' => true,
                'feature' => null,
            ],
            self::BILLING_MANAGE => [
                'name' => 'Manage billing & plan',
                'group' => 'admin',
                'description' => 'See the subscription, usage and plan options.',
                'sensitive' => true,
                'feature' => null,
            ],
            self::AUDIT_VIEW => [
                'name' => 'View activity log',
                'group' => 'admin',
                'description' => 'See who did what and when.',
                'sensitive' => true,
                'feature' => FeatureRegistry::TEAM_ACTIVITY_LOG,
            ],
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    public static function name(string $code): string
    {
        return (string) (self::all()[$code]['name'] ?? $code);
    }

    /** The subscription feature this permission rides on, if any (#187 layer 1). */
    public static function featureFor(string $code): ?string
    {
        return self::all()[$code]['feature'] ?? null;
    }

    public static function isSensitive(string $code): bool
    {
        return (bool) (self::all()[$code]['sensitive'] ?? false);
    }

    /** @return list<string> */
    public static function sensitiveCodes(): array
    {
        return array_keys(array_filter(self::all(), fn ($meta) => $meta['sensitive']));
    }

    /**
     * Registry grouped by module, for the role editor.
     *
     * @return array<string, array<string, array{name: string, group: string, description: string, sensitive: bool, feature: string|null}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $code => $meta) {
            $grouped[$meta['group']][$code] = $meta;
        }

        return $grouped;
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'overview' => 'Overview',
            'pos' => 'Point of Sale',
            'sales' => 'Sales & Payments',
            'catalog' => 'Products & Catalog',
            'inventory' => 'Inventory',
            'purchasing' => 'Purchasing & Suppliers',
            'people' => 'Customers & Team',
            'money' => 'Expenses',
            'reports' => 'Reports',
            'admin' => 'Administration',
        ];
    }

    public static function groupLabel(string $group): string
    {
        return self::groupLabels()[$group] ?? str($group)->replace('_', ' ')->title()->toString();
    }
}
