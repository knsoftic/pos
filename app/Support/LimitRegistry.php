<?php

namespace App\Support;

/**
 * The canonical catalogue of numeric quotas (#183, #78).
 *
 * Same split as {@see FeatureRegistry}: this class owns the vocabulary, the
 * `limits` table owns the per-plan values. Nothing here says "Starter gets 500
 * products" — that is operator data (#190).
 *
 * THE THREE-VALUE CONVENTION, used identically at every level
 * ----------------------------------------------------------
 *   row absent  → inherit from the level below (override → plan → this registry)
 *   value NULL  → UNLIMITED
 *   value 0     → nothing allowed at all
 *
 * NULL therefore means two different things depending on whether the row exists,
 * which is why `default_unlimited` exists: it tells the resolver whether an
 * unconfigured limit should be treated as unlimited or as `default_value`. It
 * defaults to false so a forgotten limit fails CLOSED rather than handing out
 * infinite quota.
 */
final class LimitRegistry
{
    public const PRODUCTS = 'limits.products';

    public const CATEGORIES = 'limits.categories';

    public const BRANDS = 'limits.brands';

    public const CUSTOMERS = 'limits.customers';

    public const SUPPLIERS = 'limits.suppliers';

    public const EMPLOYEES = 'limits.employees';

    public const BRANCHES = 'limits.branches';

    public const POS_COUNTERS = 'limits.pos_counters';

    public const WAREHOUSES = 'limits.warehouses';

    public const INVOICES_PER_MONTH = 'limits.invoices_per_month';

    public const SMS_PER_MONTH = 'limits.sms_per_month';

    public const STORAGE_MB = 'limits.storage_mb';

    /**
     * Every limit, in display order.
     *
     * `is_monthly` marks a quota that resets each calendar month — the usage
     * resolver counts only the current month for those, and the UI says
     * "resets on the 1st" instead of showing a permanent ceiling.
     *
     * @return array<string, array{name: string, group: string, unit: string, description: string, default_value: int|null, default_unlimited: bool, is_monthly: bool}>
     */
    public static function all(): array
    {
        return [
            self::PRODUCTS => [
                'name' => 'Products',
                'group' => 'catalog',
                'unit' => 'products',
                'description' => 'How many distinct products may exist.',
                'default_value' => 50,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::CATEGORIES => [
                'name' => 'Categories',
                'group' => 'catalog',
                'unit' => 'categories',
                'description' => 'How many product categories may exist.',
                'default_value' => 10,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::BRANDS => [
                'name' => 'Brands',
                'group' => 'catalog',
                'unit' => 'brands',
                'description' => 'How many brands may exist.',
                'default_value' => 10,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::CUSTOMERS => [
                'name' => 'Customers',
                'group' => 'people',
                'unit' => 'customers',
                'description' => 'How many customers may be on file.',
                'default_value' => 100,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::SUPPLIERS => [
                'name' => 'Suppliers',
                'group' => 'people',
                'unit' => 'suppliers',
                'description' => 'How many suppliers may be on file.',
                'default_value' => 20,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::EMPLOYEES => [
                'name' => 'Users / Employees',
                'group' => 'people',
                'unit' => 'users',
                'description' => 'How many staff logins the business may have.',
                'default_value' => 2,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::BRANCHES => [
                'name' => 'Branches',
                'group' => 'locations',
                'unit' => 'branches',
                'description' => 'How many shop locations may be set up.',
                'default_value' => 1,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::POS_COUNTERS => [
                'name' => 'POS Counters',
                'group' => 'locations',
                'unit' => 'counters',
                'description' => 'How many tills may be registered.',
                'default_value' => 1,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::WAREHOUSES => [
                'name' => 'Warehouses',
                'group' => 'locations',
                'unit' => 'warehouses',
                'description' => 'How many stock locations may exist.',
                'default_value' => 1,
                'default_unlimited' => false,
                'is_monthly' => false,
            ],
            self::INVOICES_PER_MONTH => [
                'name' => 'Invoices per Month',
                'group' => 'usage',
                'unit' => 'invoices',
                'description' => 'How many invoices may be issued each month.',
                'default_value' => 200,
                'default_unlimited' => false,
                'is_monthly' => true,
            ],
            self::SMS_PER_MONTH => [
                'name' => 'SMS per Month',
                'group' => 'usage',
                'unit' => 'messages',
                'description' => 'How many SMS messages may be sent each month.',
                'default_value' => 0,
                'default_unlimited' => false,
                'is_monthly' => true,
            ],
            self::STORAGE_MB => [
                'name' => 'File Storage',
                'group' => 'usage',
                'unit' => 'MB',
                'description' => 'Total space for product images and attachments.',
                'default_value' => 100,
                'default_unlimited' => false,
                'is_monthly' => false,
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

    /**
     * The registry-level fallback, already collapsed to the resolver contract:
     * NULL = unlimited, int = ceiling.
     */
    public static function defaultFor(string $code): ?int
    {
        $meta = self::all()[$code] ?? null;

        if ($meta === null) {
            return 0; // Unknown code: allow nothing. Fail closed.
        }

        return $meta['default_unlimited'] ? null : (int) ($meta['default_value'] ?? 0);
    }

    public static function isMonthly(string $code): bool
    {
        return (bool) (self::all()[$code]['is_monthly'] ?? false);
    }

    public static function unit(string $code): string
    {
        return (string) (self::all()[$code]['unit'] ?? 'items');
    }

    public static function name(string $code): string
    {
        return (string) (self::all()[$code]['name'] ?? $code);
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'catalog' => 'Products & Catalog',
            'people' => 'People',
            'locations' => 'Locations',
            'usage' => 'Monthly Usage',
        ];
    }

    public static function groupLabel(string $group): string
    {
        return self::groupLabels()[$group] ?? str($group)->replace('_', ' ')->title()->toString();
    }
}
