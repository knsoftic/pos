<?php

namespace App\Support;

/**
 * The canonical catalogue of feature flags (#183).
 *
 * WHY A REGISTRY AND A TABLE BOTH EXIST
 * -------------------------------------
 * Code needs compile-time-checkable names — `FeatureRegistry::POS_HOLD_SALES`
 * fails loudly on a typo, `'pos.hold_slaes'` fails silently. Operators need to
 * switch features per plan and per tenant at runtime, which means rows in a
 * table. So: this class owns the *vocabulary*, the `features` table owns the
 * *state*. The seeder syncs one into the other and is idempotent, so adding a
 * constant here plus re-running the seeder is the whole workflow for shipping a
 * new flag.
 *
 * Nothing here hardcodes which PLAN gets a feature (#190) — that is entirely
 * operator data in `plan_feature`. `default_enabled` only decides what an
 * unconfigured plan does, and it is `false` for everything that costs money to
 * run or that a paying customer would expect to pay for: fail closed.
 */
final class FeatureRegistry
{
    // ------------------------------------------------------------------- POS
    public const POS_TERMINAL = 'pos.terminal';

    public const POS_HOLD_SALES = 'pos.hold_sales';

    public const POS_SPLIT_PAYMENT = 'pos.split_payment';

    public const POS_BARCODE_SCANNER = 'pos.barcode_scanner';

    public const POS_CUSTOMER_DISPLAY = 'pos.customer_display';

    public const POS_OFFLINE_MODE = 'pos.offline_mode';

    public const POS_MULTI_COUNTER = 'pos.multi_counter';

    // ------------------------------------------------------------- inventory
    public const INVENTORY_STOCK_TRACKING = 'inventory.stock_tracking';

    public const INVENTORY_TRANSFERS = 'inventory.transfers';

    public const INVENTORY_ADJUSTMENTS = 'inventory.adjustments';

    public const INVENTORY_STOCK_TAKE = 'inventory.stock_take';

    public const INVENTORY_LOW_STOCK_ALERTS = 'inventory.low_stock_alerts';

    public const INVENTORY_EXPIRY_TRACKING = 'inventory.expiry_tracking';

    public const INVENTORY_MULTI_WAREHOUSE = 'inventory.multi_warehouse';

    // --------------------------------------------------------------- catalog
    public const CATALOG_VARIANTS = 'catalog.variants';

    public const CATALOG_SERIAL_NUMBERS = 'catalog.serial_numbers';

    public const CATALOG_BATCH_TRACKING = 'catalog.batch_tracking';

    public const CATALOG_MULTI_UNIT = 'catalog.multi_unit';

    public const CATALOG_IMPORT = 'catalog.import';

    // ----------------------------------------------------------------- sales
    public const SALES_INVOICING = 'sales.invoicing';

    public const SALES_QUOTATIONS = 'sales.quotations';

    public const SALES_RETURNS = 'sales.returns';

    public const SALES_CREDIT_SALES = 'sales.credit_sales';

    public const SALES_DISCOUNTS = 'sales.discounts';

    public const SALES_TAX = 'sales.tax';

    public const SALES_DELIVERY_NOTES = 'sales.delivery_notes';

    // ------------------------------------------------------------- purchases
    public const PURCHASES_ORDERS = 'purchases.orders';

    public const PURCHASES_RETURNS = 'purchases.returns';

    public const PURCHASES_SUPPLIER_LEDGER = 'purchases.supplier_ledger';

    // ------------------------------------------------------------ accounting
    public const ACCOUNTING_EXPENSES = 'accounting.expenses';

    public const ACCOUNTING_CUSTOMER_LEDGER = 'accounting.customer_ledger';

    public const ACCOUNTING_CASH_REGISTER = 'accounting.cash_register';

    public const ACCOUNTING_MULTI_CURRENCY = 'accounting.multi_currency';

    public const ACCOUNTING_PROFIT_LOSS = 'accounting.profit_loss';

    // --------------------------------------------------------------- reports
    public const REPORTS_BASIC = 'reports.basic';

    public const REPORTS_ADVANCED = 'reports.advanced';

    public const REPORTS_EXPORT_PDF = 'reports.export_pdf';

    public const REPORTS_EXPORT_EXCEL = 'reports.export_excel';

    public const REPORTS_SCHEDULED_EMAIL = 'reports.scheduled_email';

    public const REPORTS_DASHBOARD_CHARTS = 'reports.dashboard_charts';

    // ------------------------------------------------------------------ team
    public const TEAM_MULTI_USER = 'team.multi_user';

    public const TEAM_ROLES = 'team.roles';

    public const TEAM_ATTENDANCE = 'team.attendance';

    public const TEAM_COMMISSION = 'team.commission';

    public const TEAM_ACTIVITY_LOG = 'team.activity_log';

    // -------------------------------------------------------------- branches
    public const BRANCHES_MULTI_BRANCH = 'branches.multi_branch';

    public const BRANCHES_CONSOLIDATED_REPORTS = 'branches.consolidated_reports';

    // ------------------------------------------------------------- customers
    public const CUSTOMERS_MANAGEMENT = 'customers.management';

    public const CUSTOMERS_LOYALTY = 'customers.loyalty';

    public const CUSTOMERS_SMS = 'customers.sms';

    public const CUSTOMERS_WHATSAPP = 'customers.whatsapp';

    // -------------------------------------------------------------- settings
    public const SETTINGS_CUSTOM_INVOICE = 'settings.custom_invoice';

    public const SETTINGS_CUSTOM_LOGO = 'settings.custom_logo';

    public const SETTINGS_BACKUP = 'settings.backup';

    public const SETTINGS_API_ACCESS = 'settings.api_access';

    // -------------------------------------------------------------------- UI
    public const UI_DARK_MODE = 'ui.dark_mode';

    public const UI_MULTI_LANGUAGE = 'ui.multi_language';

    /**
     * Every feature, in display order, grouped for the plan editor UI.
     *
     * Shape: code => [name, group, description, default_enabled].
     * `sort_order` is derived from array position so reordering here reorders
     * the UI without hand-maintaining numbers.
     *
     * @return array<string, array{name: string, group: string, description: string, default_enabled: bool}>
     */
    public static function all(): array
    {
        return [
            // --- POS ---------------------------------------------------------
            self::POS_TERMINAL => [
                'name' => 'POS Terminal',
                'group' => 'pos',
                'description' => 'Access the point-of-sale selling screen.',
                'default_enabled' => true,
            ],
            self::POS_HOLD_SALES => [
                'name' => 'Hold / Park Sales',
                'group' => 'pos',
                'description' => 'Park an in-progress sale and resume it later.',
                'default_enabled' => false,
            ],
            self::POS_SPLIT_PAYMENT => [
                'name' => 'Split Payment',
                'group' => 'pos',
                'description' => 'Settle one sale across multiple payment methods.',
                'default_enabled' => false,
            ],
            self::POS_BARCODE_SCANNER => [
                'name' => 'Barcode Scanning',
                'group' => 'pos',
                'description' => 'Add items to the cart with a barcode scanner.',
                'default_enabled' => true,
            ],
            self::POS_CUSTOMER_DISPLAY => [
                'name' => 'Customer Facing Display',
                'group' => 'pos',
                'description' => 'Mirror the running total on a second screen.',
                'default_enabled' => false,
            ],
            self::POS_OFFLINE_MODE => [
                'name' => 'Offline Mode',
                'group' => 'pos',
                'description' => 'Keep selling without internet and sync later.',
                'default_enabled' => false,
            ],
            self::POS_MULTI_COUNTER => [
                'name' => 'Multiple Counters',
                'group' => 'pos',
                'description' => 'Run more than one till per branch.',
                'default_enabled' => false,
            ],

            // --- Inventory ---------------------------------------------------
            self::INVENTORY_STOCK_TRACKING => [
                'name' => 'Stock Tracking',
                'group' => 'inventory',
                'description' => 'Track quantity on hand as stock moves.',
                'default_enabled' => true,
            ],
            self::INVENTORY_TRANSFERS => [
                'name' => 'Stock Transfers',
                'group' => 'inventory',
                'description' => 'Move stock between branches or warehouses.',
                'default_enabled' => false,
            ],
            self::INVENTORY_ADJUSTMENTS => [
                'name' => 'Stock Adjustments',
                'group' => 'inventory',
                'description' => 'Correct quantities with an audited reason.',
                'default_enabled' => true,
            ],
            self::INVENTORY_STOCK_TAKE => [
                'name' => 'Physical Stock Take',
                'group' => 'inventory',
                'description' => 'Run a full count and reconcile differences.',
                'default_enabled' => false,
            ],
            self::INVENTORY_LOW_STOCK_ALERTS => [
                'name' => 'Low Stock Alerts',
                'group' => 'inventory',
                'description' => 'Get warned before a product runs out.',
                'default_enabled' => true,
            ],
            self::INVENTORY_EXPIRY_TRACKING => [
                'name' => 'Expiry Tracking',
                'group' => 'inventory',
                'description' => 'Track expiry dates and flag near-expiry stock.',
                'default_enabled' => false,
            ],
            self::INVENTORY_MULTI_WAREHOUSE => [
                'name' => 'Multiple Warehouses',
                'group' => 'inventory',
                'description' => 'Hold stock in more than one location.',
                'default_enabled' => false,
            ],

            // --- Catalog -----------------------------------------------------
            self::CATALOG_VARIANTS => [
                'name' => 'Product Variants',
                'group' => 'catalog',
                'description' => 'Size / colour style variants under one product.',
                'default_enabled' => false,
            ],
            self::CATALOG_SERIAL_NUMBERS => [
                'name' => 'Serial Numbers (IMEI)',
                'group' => 'catalog',
                'description' => 'Track individual units by serial or IMEI.',
                'default_enabled' => false,
            ],
            self::CATALOG_BATCH_TRACKING => [
                'name' => 'Batch / Lot Tracking',
                'group' => 'catalog',
                'description' => 'Group stock into batches with their own costs.',
                'default_enabled' => false,
            ],
            self::CATALOG_MULTI_UNIT => [
                'name' => 'Multiple Units',
                'group' => 'catalog',
                'description' => 'Buy in cartons, sell in pieces.',
                'default_enabled' => false,
            ],
            self::CATALOG_IMPORT => [
                'name' => 'Bulk Import',
                'group' => 'catalog',
                'description' => 'Import products and opening stock from a file.',
                'default_enabled' => false,
            ],

            // --- Sales -------------------------------------------------------
            self::SALES_INVOICING => [
                'name' => 'Sales Invoicing',
                'group' => 'sales',
                'description' => 'Create and print sales invoices.',
                'default_enabled' => true,
            ],
            self::SALES_QUOTATIONS => [
                'name' => 'Quotations',
                'group' => 'sales',
                'description' => 'Issue quotes and convert them to invoices.',
                'default_enabled' => false,
            ],
            self::SALES_RETURNS => [
                'name' => 'Sales Returns',
                'group' => 'sales',
                'description' => 'Accept returns and issue credit.',
                'default_enabled' => true,
            ],
            self::SALES_CREDIT_SALES => [
                'name' => 'Credit Sales',
                'group' => 'sales',
                'description' => 'Sell on account and track receivables.',
                'default_enabled' => false,
            ],
            self::SALES_DISCOUNTS => [
                'name' => 'Discounts',
                'group' => 'sales',
                'description' => 'Line-level and invoice-level discounts.',
                'default_enabled' => true,
            ],
            self::SALES_TAX => [
                'name' => 'Tax / VAT',
                'group' => 'sales',
                'description' => 'Configurable tax rates on sales.',
                'default_enabled' => true,
            ],
            self::SALES_DELIVERY_NOTES => [
                'name' => 'Delivery Notes',
                'group' => 'sales',
                'description' => 'Ship goods against an order with a delivery note.',
                'default_enabled' => false,
            ],

            // --- Purchases ---------------------------------------------------
            self::PURCHASES_ORDERS => [
                'name' => 'Purchase Orders',
                'group' => 'purchases',
                'description' => 'Raise purchase orders and receive goods.',
                'default_enabled' => true,
            ],
            self::PURCHASES_RETURNS => [
                'name' => 'Purchase Returns',
                'group' => 'purchases',
                'description' => 'Return goods to a supplier.',
                'default_enabled' => true,
            ],
            self::PURCHASES_SUPPLIER_LEDGER => [
                'name' => 'Supplier Ledger',
                'group' => 'purchases',
                'description' => 'Running payables balance per supplier.',
                'default_enabled' => false,
            ],

            // --- Accounting --------------------------------------------------
            self::ACCOUNTING_EXPENSES => [
                'name' => 'Expense Tracking',
                'group' => 'accounting',
                'description' => 'Record business expenses by category.',
                'default_enabled' => true,
            ],
            self::ACCOUNTING_CUSTOMER_LEDGER => [
                'name' => 'Customer Ledger',
                'group' => 'accounting',
                'description' => 'Running receivables balance per customer.',
                'default_enabled' => false,
            ],
            self::ACCOUNTING_CASH_REGISTER => [
                'name' => 'Cash Register / Day Close',
                'group' => 'accounting',
                'description' => 'Open and close the till with cash counting.',
                'default_enabled' => false,
            ],
            self::ACCOUNTING_MULTI_CURRENCY => [
                'name' => 'Multi Currency',
                'group' => 'accounting',
                'description' => 'Transact in more than one currency.',
                'default_enabled' => false,
            ],
            self::ACCOUNTING_PROFIT_LOSS => [
                'name' => 'Profit & Loss',
                'group' => 'accounting',
                'description' => 'Profit reporting with cost of goods sold.',
                'default_enabled' => false,
            ],

            // --- Reports -----------------------------------------------------
            self::REPORTS_BASIC => [
                'name' => 'Basic Reports',
                'group' => 'reports',
                'description' => 'Daily sales, stock on hand, top products.',
                'default_enabled' => true,
            ],
            self::REPORTS_ADVANCED => [
                'name' => 'Advanced Reports',
                'group' => 'reports',
                'description' => 'Profitability, trends and comparisons.',
                'default_enabled' => false,
            ],
            self::REPORTS_EXPORT_PDF => [
                'name' => 'Export to PDF',
                'group' => 'reports',
                'description' => 'Download any report as a PDF.',
                'default_enabled' => false,
            ],
            self::REPORTS_EXPORT_EXCEL => [
                'name' => 'Export to Excel / CSV',
                'group' => 'reports',
                'description' => 'Download any report as a spreadsheet.',
                'default_enabled' => false,
            ],
            self::REPORTS_SCHEDULED_EMAIL => [
                'name' => 'Scheduled Report Email',
                'group' => 'reports',
                'description' => 'Email chosen reports on a schedule.',
                'default_enabled' => false,
            ],
            self::REPORTS_DASHBOARD_CHARTS => [
                'name' => 'Dashboard Charts',
                'group' => 'reports',
                'description' => 'Visual charts on the dashboard.',
                'default_enabled' => true,
            ],

            // --- Team --------------------------------------------------------
            self::TEAM_MULTI_USER => [
                'name' => 'Multiple Users',
                'group' => 'team',
                'description' => 'Invite staff to their own logins.',
                'default_enabled' => true,
            ],
            self::TEAM_ROLES => [
                'name' => 'Roles & Permissions',
                'group' => 'team',
                'description' => 'Custom roles with granular permissions.',
                'default_enabled' => false,
            ],
            self::TEAM_ATTENDANCE => [
                'name' => 'Attendance',
                'group' => 'team',
                'description' => 'Staff check-in / check-out records.',
                'default_enabled' => false,
            ],
            self::TEAM_COMMISSION => [
                'name' => 'Sales Commission',
                'group' => 'team',
                'description' => 'Commission rules and payout reporting.',
                'default_enabled' => false,
            ],
            self::TEAM_ACTIVITY_LOG => [
                'name' => 'Activity Log',
                'group' => 'team',
                'description' => 'See who changed what, and when.',
                'default_enabled' => false,
            ],

            // --- Branches ----------------------------------------------------
            self::BRANCHES_MULTI_BRANCH => [
                'name' => 'Multiple Branches',
                'group' => 'branches',
                'description' => 'Run more than one shop location.',
                'default_enabled' => false,
            ],
            self::BRANCHES_CONSOLIDATED_REPORTS => [
                'name' => 'Consolidated Reports',
                'group' => 'branches',
                'description' => 'Roll all branches up into one report.',
                'default_enabled' => false,
            ],

            // --- Customers ---------------------------------------------------
            self::CUSTOMERS_MANAGEMENT => [
                'name' => 'Customer Management',
                'group' => 'customers',
                'description' => 'Keep a customer directory with history.',
                'default_enabled' => true,
            ],
            self::CUSTOMERS_LOYALTY => [
                'name' => 'Loyalty Points',
                'group' => 'customers',
                'description' => 'Earn and redeem loyalty points.',
                'default_enabled' => false,
            ],
            self::CUSTOMERS_SMS => [
                'name' => 'SMS Notifications',
                'group' => 'customers',
                'description' => 'Send receipts and reminders by SMS.',
                'default_enabled' => false,
            ],
            self::CUSTOMERS_WHATSAPP => [
                'name' => 'WhatsApp Notifications',
                'group' => 'customers',
                'description' => 'Send receipts and reminders on WhatsApp.',
                'default_enabled' => false,
            ],

            // --- Settings ----------------------------------------------------
            self::SETTINGS_CUSTOM_INVOICE => [
                'name' => 'Custom Invoice Template',
                'group' => 'settings',
                'description' => 'Choose and customise the invoice layout.',
                'default_enabled' => false,
            ],
            self::SETTINGS_CUSTOM_LOGO => [
                'name' => 'Custom Logo',
                'group' => 'settings',
                'description' => 'Put your own logo on the app and invoices.',
                'default_enabled' => true,
            ],
            self::SETTINGS_BACKUP => [
                'name' => 'Data Backup / Export',
                'group' => 'settings',
                'description' => 'Download a full export of your own data.',
                'default_enabled' => false,
            ],
            self::SETTINGS_API_ACCESS => [
                'name' => 'API Access',
                'group' => 'settings',
                'description' => 'Programmatic access with API tokens.',
                'default_enabled' => false,
            ],

            // --- UI ----------------------------------------------------------
            self::UI_DARK_MODE => [
                'name' => 'Dark Mode',
                'group' => 'ui',
                'description' => 'Light / dark theme switching.',
                'default_enabled' => true,
            ],
            self::UI_MULTI_LANGUAGE => [
                'name' => 'Multiple Languages',
                'group' => 'ui',
                'description' => 'Switch the interface language.',
                'default_enabled' => false,
            ],
        ];
    }

    /** @return list<string> Every known feature code. */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    /** @return array<string, array{name: string, group: string, description: string, default_enabled: bool}> */
    public static function group(string $group): array
    {
        return array_filter(self::all(), fn (array $meta): bool => $meta['group'] === $group);
    }

    /**
     * Group keys in display order, with their human labels.
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'pos' => 'Point of Sale',
            'inventory' => 'Inventory',
            'catalog' => 'Products & Catalog',
            'sales' => 'Sales',
            'purchases' => 'Purchases',
            'accounting' => 'Accounting',
            'reports' => 'Reports',
            'team' => 'Team',
            'branches' => 'Branches',
            'customers' => 'Customers',
            'settings' => 'Settings',
            'ui' => 'Interface',
        ];
    }

    public static function groupLabel(string $group): string
    {
        return self::groupLabels()[$group] ?? str($group)->replace('_', ' ')->title()->toString();
    }

    /** Codes whose `default_enabled` is true — what an unconfigured plan allows. */
    public static function defaultEnabledCodes(): array
    {
        return array_keys(array_filter(self::all(), fn (array $m): bool => $m['default_enabled']));
    }
}
