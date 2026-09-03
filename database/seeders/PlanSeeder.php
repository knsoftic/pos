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
 * The starting plan catalogue, priced for Pakistani shops.
 *
 * ⚠️ This is seed DATA, not business logic. Nothing in the application reads a
 * plan name, price, feature set or quota from code (#190) — the operator edits
 * all of it in /admin/plans, and these rows are only a sensible starting point
 * so a fresh install is not staring at an empty catalogue.
 *
 * ═══ THE ONE RULE THIS FILE KEEPS ═══════════════════════════════════════════
 *
 * A PLAN NEVER SELLS A FEATURE THAT IS NOT BUILT.
 *
 * The registry lists 57 features because it doubles as the roadmap. Seventeen
 * of them gate nothing today — there is no table, no screen and no code behind
 * Loyalty Points, SMS, Quotations, Serial Numbers, Offline Mode and the rest.
 * They stay OFF in every plan here. Switching one on would not make it work; it
 * would put a line on the pricing page that the shop pays for and never finds,
 * and the shop would be right to ask for its money back.
 *
 * When one of them gets built, the operator turns it on in /admin/plans — or a
 * later version of this file does. Until then the honest answer is off.
 *
 * ═══ AND ITS COROLLARY ══════════════════════════════════════════════════════
 *
 * A FEATURE NOTHING GATES MUST BE ON IN EVERY PLAN.
 *
 * Some things in the registry are not separable capabilities at all — scanning
 * a barcode is typing into the search box, and holding a sale is a status on a
 * row. No code asks the plan's permission, so they work for everybody whatever
 * the pivot says. Enabling them everywhere is then the only truthful position:
 * put such a feature on a paid tier and the comparison matrix promises the
 * cheap tier is missing something it actually has.
 *
 * tests/Feature/Subscription/PlanCatalogueTest.php holds both rules down, by
 * reading which codes the application really gates on rather than trusting a
 * list — a hand-kept list goes stale the first time somebody builds something,
 * and it goes stale in the direction that hides the bug.
 *
 * ═══ THE PRICES ═════════════════════════════════════════════════════════════
 *
 * PKR, aimed at an ordinary middle-class shop — a kiryana store, a mobile or
 * garments shop, a small pharmacy. Such a shop is not going to hand over five
 * figures a month for software, so the ladder starts at a thousand rupees and
 * the free tier is genuinely usable rather than a crippled demo.
 *
 *   quarterly   = 10% off the monthly rate
 *   half-yearly = 15% off
 *   yearly      = pay for ten months, get twelve
 *
 * ⚠️ The numbers are plain PKR. If the operator's billing currency is set to
 * anything else these become that currency's numbers — the setting relabels,
 * it does not convert. {@see config('subscription.currency')}.
 *
 * Every plan gets an EXPLICIT pivot row for every registry feature (enabled or
 * not) rather than leaning on `features.default_enabled`. That costs a few
 * hundred rows and buys an unambiguous comparison matrix (#84): "not in this
 * plan" and "nobody has configured this yet" stop looking identical.
 *
 * Re-running is safe: plans are matched by slug and their pivots re-synced.
 * ⚠️ Re-running also DISCARDS hand edits made in /admin/plans for these five
 * slugs. Subscriptions are unaffected — they point at the plan row, which keeps
 * its id — but what those subscribers are entitled to changes to match.
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
     * Everything that actually works today, in the order a shop grows into it.
     *
     * ⚠️ Anything absent from this list is absent on purpose — see the class
     * docblock. Adding a code here without building the thing behind it is the
     * one change this file must never take.
     *
     * @return list<string>
     */
    public static function builtFeatures(): array
    {
        return array_merge(
            self::freeFeatures(),
            self::starterAdds(),
            self::professionalAdds(),
            self::businessAdds(),
        );
    }

    /**
     * Free — enough to run a small counter honestly, not a crippled demo.
     *
     * Holding a sale is in here rather than sold as an upgrade because a
     * customer walking off mid-transaction is not a premium event; a till that
     * cannot park a basket is a till that loses the queue behind it.
     *
     * ⚠️ Three of these are here because nothing gates them — see
     * {@see self::worksRegardlessOfPlan()}. On everywhere, or the matrix lies
     * about what the cheap tier is missing.
     *
     * @return list<string>
     */
    protected static function freeFeatures(): array
    {
        return array_merge(self::worksRegardlessOfPlan(), [
            F::POS_TERMINAL,

            /*
             | ⚠️ GATED, but gated on the LABEL SHEET rather than on scanning.
             | Scanning at the till is just typing into the search box and no
             | code checks the plan for it; what `feature:pos.barcode_scanner`
             | actually guards is /app/products/labels, where a shop prints its
             | own codes for loose goods.
             |
             | It sits in Free because a shop weighing out rice cannot scan
             | anything until it has printed a label for it — selling the
             | printer separately from the scanner would leave the cheap tier
             | with a reader and nothing to read.
             */
            F::POS_BARCODE_SCANNER,

            F::INVENTORY_STOCK_TRACKING,
            F::INVENTORY_ADJUSTMENTS,
            F::SALES_INVOICING,
            F::SALES_RETURNS,
            F::SALES_DISCOUNTS,
            F::PURCHASES_ORDERS,
            F::CUSTOMERS_MANAGEMENT,
            F::REPORTS_BASIC,
            F::UI_DARK_MODE,
        ]);
    }

    /**
     * Built, working, and gated by nothing — so every shop has them whatever
     * its plan says.
     *
     * These are not separable capabilities. Holding a sale is a status on a row.
     * Splitting a tender is two rows in `sale_payments` instead of one. The
     * low-stock warning is a column compared against a quantity. There is no
     * seam to charge at, and writing a gate around one would be inventing a
     * restriction to sell rather than building something.
     *
     * ⚠️ Barcode scanning was on this list and should not have been. It IS
     * gated -- but as a middleware STRING, `feature:pos.barcode_scanner`, not as
     * a constant, so the scan that decides this missed it. The list was right
     * about the plan (it belongs in Free either way) and wrong about the reason,
     * which is exactly the kind of wrong that survives a review.
     *
     * ⚠️ So they belong in EVERY tier. Put one on a paid plan and the pricing
     * matrix tells the cheap tier it is missing something it uses all day.
     *
     * If a real gate is ever added around one of these, move it out of here and
     * into whichever tier it belongs to — the catalogue test will then let it be
     * sold as a difference, because by then it will be one.
     *
     * @return list<string>
     */
    public static function worksRegardlessOfPlan(): array
    {
        return [
            F::POS_HOLD_SALES,
            F::POS_SPLIT_PAYMENT,
            F::INVENTORY_LOW_STOCK_ALERTS,
        ];
    }

    /**
     * Starter — one proper shop, doing what shops here actually do.
     *
     * ⚠️ UDHAAR AND KHATA LIVE AT THE BOTTOM OF THE LADDER, NOT THE TOP. Credit
     * sales and a customer ledger are not advanced accounting in this market;
     * they are how an ordinary kiryana does business every single day. Selling
     * them at the top tier would make this tier useless to the shop it is aimed
     * at. Same reasoning for multi-unit: a shop weighing out sugar by the kilo
     * and selling loose by the gram needs it on day one.
     *
     * @return list<string>
     */
    protected static function starterAdds(): array
    {
        return [
            F::CATALOG_IMPORT,
            F::CATALOG_MULTI_UNIT,
            F::SALES_TAX,
            F::SALES_CREDIT_SALES,
            F::PURCHASES_RETURNS,
            F::PURCHASES_SUPPLIER_LEDGER,
            F::ACCOUNTING_CUSTOMER_LEDGER,
            F::ACCOUNTING_EXPENSES,
            F::ACCOUNTING_CASH_REGISTER,
            F::REPORTS_EXPORT_PDF,
            F::REPORTS_DASHBOARD_CHARTS,
            F::TEAM_MULTI_USER,
            F::SETTINGS_CUSTOM_LOGO,
            F::SETTINGS_CUSTOM_INVOICE,
        ];
    }

    /**
     * Professional — a busy shop with staff, several tills and stock worth
     * counting properly.
     *
     * @return list<string>
     */
    protected static function professionalAdds(): array
    {
        return [
            F::POS_MULTI_COUNTER,
            F::INVENTORY_STOCK_TAKE,
            F::INVENTORY_EXPIRY_TRACKING,
            F::CATALOG_VARIANTS,
            F::CATALOG_BATCH_TRACKING,
            F::ACCOUNTING_PROFIT_LOSS,
            F::REPORTS_ADVANCED,
            F::REPORTS_EXPORT_EXCEL,
            F::TEAM_ROLES,
            F::TEAM_ACTIVITY_LOG,
        ];
    }

    /**
     * Business — more than one shop.
     *
     * Only two features, and that is honest rather than thin: this tier is
     * bought for its LIMITS. Stock transfers are here and not a step lower
     * because there is nowhere to transfer stock to until a second branch
     * exists — warehouses are not built, so branches are the only places stock
     * can sit.
     *
     * @return list<string>
     */
    protected static function businessAdds(): array
    {
        return [
            F::BRANCHES_MULTI_BRANCH,
            F::INVENTORY_TRANSFERS,
        ];
    }

    /**
     * Cumulative tiers — each level lists what it adds, so a feature can only be
     * forgotten in one place instead of five.
     *
     * @return list<array<string, mixed>>
     */
    protected function plans(): array
    {
        $free = self::freeFeatures();
        $starter = array_merge($free, self::starterAdds());
        $professional = array_merge($starter, self::professionalAdds());
        $business = array_merge($professional, self::businessAdds());

        return [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'One counter, a small catalogue, and every sale recorded properly. No card, no trial clock.',
                'sort_order' => 10,
                'trial_days' => 0,
                'prices' => [BillingCycle::Monthly->value => 0],
                'features' => $free,
                'limits' => [
                    L::PRODUCTS => 50,
                    L::CATEGORIES => 10,
                    L::BRANDS => 10,
                    L::CUSTOMERS => 100,
                    L::SUPPLIERS => 10,
                    L::EMPLOYEES => 2,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 1,
                    L::WAREHOUSES => 1,
                    // ~10 sales a day. A real shop outgrows this in a month,
                    // which is the point — but it is not a two-day teaser.
                    L::INVOICES_PER_MONTH => 300,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 100,
                ],
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For one shop running on a cash drawer and a khata notebook. Udhaar, khata, expenses and stock, all in one place.',
                'sort_order' => 20,
                'trial_days' => 14,
                'prices' => [
                    BillingCycle::Monthly->value => 1000,
                    BillingCycle::Quarterly->value => 2700,
                    BillingCycle::Yearly->value => 10000,
                ],
                'features' => $starter,
                'limits' => [
                    // A kiryana carries more lines than people expect — a
                    // 500-product cap would be hit while entering the shelves.
                    L::PRODUCTS => 1000,
                    L::CATEGORIES => 50,
                    L::BRANDS => 50,
                    L::CUSTOMERS => 1000,
                    L::SUPPLIERS => 100,
                    L::EMPLOYEES => 4,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 2,
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => 3000,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 500,
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'A busy shop with staff and more than one till: roles, stock take, batches and expiry, and profit you can actually read.',
                'badge' => 'Most popular',
                'sort_order' => 30,
                'trial_days' => 14,
                'prices' => [
                    BillingCycle::Monthly->value => 2500,
                    BillingCycle::Quarterly->value => 6750,
                    BillingCycle::HalfYearly->value => 12750,
                    BillingCycle::Yearly->value => 25000,
                ],
                'features' => $professional,
                'limits' => [
                    L::PRODUCTS => 10000,
                    L::CATEGORIES => 200,
                    L::BRANDS => 200,
                    L::CUSTOMERS => 10000,
                    L::SUPPLIERS => 500,
                    L::EMPLOYEES => 12,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 5,
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => 20000,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 2000,
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'More than one shop. Branches, stock transfers between them, and nothing counted or capped.',
                'sort_order' => 40,
                'trial_days' => 14,
                'grace_days' => 7,
                'prices' => [
                    BillingCycle::Monthly->value => 6000,
                    BillingCycle::Quarterly->value => 16200,
                    BillingCycle::HalfYearly->value => 30600,
                    BillingCycle::Yearly->value => 60000,
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
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => null,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 10000,
                ],
            ],
            [
                'slug' => 'lifetime',
                'name' => 'Lifetime',
                'description' => 'Pay once, keep Professional for good. Sold in limited batches.',
                'badge' => 'One-time',
                'sort_order' => 50,
                'is_public' => false,
                // Three years of Professional at the yearly rate. Below that it
                // costs the operator money to honour; far above it and nobody
                // buys, because the arithmetic is easy to do.
                'prices' => [BillingCycle::Lifetime->value => 75000],
                'features' => $professional,
                'limits' => [
                    L::PRODUCTS => 10000,
                    L::CATEGORIES => 200,
                    L::BRANDS => 200,
                    L::CUSTOMERS => 10000,
                    L::SUPPLIERS => 500,
                    L::EMPLOYEES => 12,
                    L::BRANCHES => 1,
                    L::POS_COUNTERS => 5,
                    L::WAREHOUSES => 1,
                    L::INVOICES_PER_MONTH => 20000,
                    L::SMS_PER_MONTH => 0,
                    L::STORAGE_MB => 2000,
                ],
            ],
        ];
    }
}
