<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Support\FeatureRegistry as F;
use App\Support\LimitRegistry as L;
use Illuminate\Database\Seeder;

/**
 * DEMO / STARTING plan catalogue.
 *
 * ⚠️ This is seed DATA, not business logic. Nothing in the application reads a
 * plan name, price, feature set or quota from code (#190) — the operator edits
 * all of it in /admin/plans, and these rows are only a sensible starting point
 * so a fresh install is not staring at an empty catalogue.
 *
 * Every plan gets an EXPLICIT pivot row for every registry feature (enabled or
 * not) rather than leaning on `features.default_enabled`. That costs a few
 * hundred rows and buys an unambiguous comparison matrix (#84): "not in this
 * plan" and "nobody has configured this yet" stop looking identical.
 *
 * Re-running is safe: plans are matched by slug and their pivots re-synced.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $featureIds = Feature::query()->pluck('id', 'code');
        $limitIds = Limit::query()->pluck('id', 'code');

        if ($featureIds->isEmpty() || $limitIds->isEmpty()) {
            $this->command?->warn('  Skipping plans: run FeatureSeeder and LimitSeeder first.');

            return;
        }

        foreach ($this->plans() as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'badge' => $definition['badge'] ?? null,
                    'trial_days' => $definition['trial_days'] ?? null,
                    'grace_days' => $definition['grace_days'] ?? null,
                    'is_active' => true,
                    'is_public' => $definition['is_public'] ?? true,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            // ---- prices -----------------------------------------------------
            foreach ($definition['prices'] as $cycle => $price) {
                $plan->prices()->updateOrCreate(
                    ['billing_cycle' => $cycle, 'custom_days' => null],
                    ['price' => $price, 'is_active' => true],
                );
            }

            // ---- features: every code, explicitly on or off ------------------
            $enabled = $definition['features'];
            $pivot = [];

            foreach ($featureIds as $code => $id) {
                $pivot[$id] = ['is_enabled' => in_array($code, $enabled, true)];
            }

            $plan->features()->sync($pivot);

            // ---- limits: NULL = unlimited ------------------------------------
            $limitPivot = [];

            foreach ($definition['limits'] as $code => $value) {
                if (! isset($limitIds[$code])) {
                    continue;
                }

                $limitPivot[$limitIds[$code]] = ['value' => $value];
            }

            $plan->limits()->sync($limitPivot);
        }

        $this->command?->info('  Plans seeded: '.Plan::query()->count());
    }

    /**
     * Cumulative tiers — each level lists what it adds, so a feature can only be
     * forgotten in one place instead of five.
     *
     * @return list<array<string, mixed>>
     */
    protected function plans(): array
    {
        $free = [
            F::POS_TERMINAL,
            F::POS_BARCODE_SCANNER,
            F::INVENTORY_STOCK_TRACKING,
            F::INVENTORY_ADJUSTMENTS,
            F::SALES_INVOICING,
            F::SALES_RETURNS,
            F::SALES_DISCOUNTS,
            F::PURCHASES_ORDERS,
            F::REPORTS_BASIC,
            F::CUSTOMERS_MANAGEMENT,
            F::TEAM_MULTI_USER,
            F::UI_DARK_MODE,
        ];

        $starter = array_merge($free, [
            F::POS_HOLD_SALES,
            F::INVENTORY_LOW_STOCK_ALERTS,
            F::CATALOG_IMPORT,
            F::SALES_TAX,
            F::PURCHASES_RETURNS,
            F::ACCOUNTING_EXPENSES,
            F::REPORTS_DASHBOARD_CHARTS,
            F::REPORTS_EXPORT_PDF,
            F::SETTINGS_CUSTOM_LOGO,
        ]);

        $professional = array_merge($starter, [
            F::POS_SPLIT_PAYMENT,
            F::POS_CUSTOMER_DISPLAY,
            F::POS_MULTI_COUNTER,
            F::INVENTORY_TRANSFERS,
            F::INVENTORY_STOCK_TAKE,
            F::CATALOG_VARIANTS,
            F::CATALOG_MULTI_UNIT,
            F::SALES_QUOTATIONS,
            F::SALES_CREDIT_SALES,
            F::SALES_DELIVERY_NOTES,
            F::PURCHASES_SUPPLIER_LEDGER,
            F::ACCOUNTING_CUSTOMER_LEDGER,
            F::ACCOUNTING_CASH_REGISTER,
            F::ACCOUNTING_PROFIT_LOSS,
            F::REPORTS_ADVANCED,
            F::REPORTS_EXPORT_EXCEL,
            F::TEAM_ROLES,
            F::TEAM_ACTIVITY_LOG,
            F::CUSTOMERS_LOYALTY,
            F::SETTINGS_CUSTOM_INVOICE,
            F::SETTINGS_BACKUP,
        ]);

        // Top tier: everything in the registry.
        $business = F::codes();

        return [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Run a single counter and learn the system. No card required.',
                'sort_order' => 10,
                'trial_days' => 0,
                'prices' => [BillingCycle::Monthly->value => 0],
                'features' => $free,
                'limits' => [
                    L::PRODUCTS => 50,
                    L::CATEGORIES => 5,
                    L::BRANDS => 5,
                    L::CUSTOMERS => 50,
                    L::SUPPLIERS => 10,
                    L::EMPLOYEES => 2,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 1,
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => 100,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 50,
                ],
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For a single shop that has outgrown a cash drawer and a notebook.',
                'sort_order' => 20,
                'trial_days' => 14,
                'prices' => [
                    BillingCycle::Monthly->value => 15,
                    BillingCycle::Quarterly->value => 42,
                    BillingCycle::Yearly->value => 150,
                ],
                'features' => $starter,
                'limits' => [
                    L::PRODUCTS => 500,
                    L::CATEGORIES => 25,
                    L::BRANDS => 25,
                    L::CUSTOMERS => 500,
                    L::SUPPLIERS => 50,
                    L::EMPLOYEES => 3,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 2,
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => 1000,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 250,
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'Multi-counter selling with full accounting and advanced reporting.',
                'badge' => 'Most popular',
                'sort_order' => 30,
                'trial_days' => 14,
                'prices' => [
                    BillingCycle::Monthly->value => 39,
                    BillingCycle::Quarterly->value => 110,
                    BillingCycle::HalfYearly->value => 210,
                    BillingCycle::Yearly->value => 390,
                ],
                'features' => $professional,
                'limits' => [
                    L::PRODUCTS => 5000,
                    L::CATEGORIES => 100,
                    L::BRANDS => 100,
                    L::CUSTOMERS => 5000,
                    L::SUPPLIERS => 500,
                    L::EMPLOYEES => 10,
                    L::BRANCHES => 2,
                    L::POS_COUNTERS => 5,
                    L::WAREHOUSES => 2,
                    L::INVOICES_PER_MONTH => 10000,
                    L::SMS_PER_MONTH => 250,
                    L::STORAGE_MB => 2000,
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Every feature, unlimited catalogue, as many branches as you run.',
                'sort_order' => 40,
                'trial_days' => 14,
                'grace_days' => 7,
                'prices' => [
                    BillingCycle::Monthly->value => 89,
                    BillingCycle::Quarterly->value => 250,
                    BillingCycle::HalfYearly->value => 480,
                    BillingCycle::Yearly->value => 890,
                ],
                'features' => $business,
                // NULL = unlimited.
                'limits' => [
                    L::PRODUCTS => null,
                    L::CATEGORIES => null,
                    L::BRANDS => null,
                    L::CUSTOMERS => null,
                    L::SUPPLIERS => null,
                    L::EMPLOYEES => null,
                    L::BRANCHES => null,
                    L::POS_COUNTERS => null,
                    L::WAREHOUSES => null,
                    L::INVOICES_PER_MONTH => null,
                    L::SMS_PER_MONTH => 2000,
                    L::STORAGE_MB => 20000,
                ],
            ],
            [
                'slug' => 'lifetime',
                'name' => 'Lifetime',
                'description' => 'Pay once, use Professional forever. Sold in limited batches.',
                'badge' => 'One-time',
                'sort_order' => 50,
                'is_public' => false,
                'prices' => [BillingCycle::Lifetime->value => 899],
                'features' => $professional,
                'limits' => [
                    L::PRODUCTS => 5000,
                    L::CATEGORIES => 100,
                    L::BRANDS => 100,
                    L::CUSTOMERS => 5000,
                    L::SUPPLIERS => 500,
                    L::EMPLOYEES => 10,
                    L::BRANCHES => 2,
                    L::POS_COUNTERS => 5,
                    L::WAREHOUSES => 2,
                    L::INVOICES_PER_MONTH => 10000,
                    L::SMS_PER_MONTH => 250,
                    L::STORAGE_MB => 2000,
                ],
            ],
        ];
    }
}
