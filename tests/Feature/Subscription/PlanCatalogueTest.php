<?php

namespace Tests\Feature\Subscription;

use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shipped plan catalogue, held to one promise: WHAT IS SOLD EXISTS.
 *
 * The registry lists 57 features because it doubles as a roadmap. Seventeen of
 * them gate nothing — no table, no screen, no code. A plan that switches one on
 * does not make it work; it puts a line on the pricing page that the shop pays
 * for and never finds.
 *
 * ⚠️ This is not a hypothetical. A shipped plan once required a supplier on
 * every purchase while keeping the whole supplier module switched off — nobody
 * noticed until a real shop could not file its first delivery. The check below
 * is that bug, generalised: it reads the feature codes the APPLICATION actually
 * gates on, and refuses any plan selling something outside that set.
 *
 * So this test needs no updating when a feature gets built. Gate it anywhere in
 * app/, routes/ or resources/views/ and it becomes sellable on its own.
 */
class PlanCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    // ══════════════════════════════════════════════ what is sold must exist

    public function test_every_sold_feature_is_either_gated_or_declared_ungated_on_purpose(): void
    {
        /*
         | No scan can tell "built but not separable" from "not built at all" —
         | both look like silence in the source. So the seeder names the first
         | set out loud in worksRegardlessOfPlan(), and everything else must
         | earn its place by being gated somewhere.
         |
         | That leaves exactly one way to sell something that does not exist:
         | add it to that named list. Which is a deliberate, four-line, reviewed
         | act rather than a checkbox nobody looked at twice.
         */
        $gated = $this->featureCodesTheApplicationGatesOn();

        // Sanity: if the scan finds almost nothing it has broken, and a broken
        // scan would pass this test by accident rather than by being right.
        $this->assertGreaterThan(25, count($gated), 'The gate scan found too little to be trusted.');

        $allowed = array_merge($gated, PlanSeeder::worksRegardlessOfPlan());

        foreach (Plan::query()->with('features')->get() as $plan) {
            foreach ($plan->features->where('pivot.is_enabled', true) as $feature) {
                $this->assertContains(
                    $feature->code,
                    $allowed,
                    "Plan \"{$plan->name}\" sells \"{$feature->code}\", which nothing in the application checks. "
                        .'Gate it where it is implemented, or take it out of the plan.'
                );
            }
        }
    }

    public function test_the_ungated_list_has_not_become_a_dumping_ground(): void
    {
        // ⚠️ The one loophole above, watched. Every entry must at least be real:
        // something with no gate AND no implementation does not belong here, it
        // belongs on the roadmap, switched off.
        $ungated = array_diff(FeatureRegistry::codes(), $this->featureCodesTheApplicationGatesOn());

        foreach (PlanSeeder::worksRegardlessOfPlan() as $code) {
            $this->assertContains($code, $ungated, "\"{$code}\" is gated now — move it into the tier it belongs to.");
        }

        // Seventeen registry entries are roadmap. If this list ever approaches
        // that size, somebody has been shipping promises instead of features.
        $this->assertLessThanOrEqual(6, count(PlanSeeder::worksRegardlessOfPlan()));
    }

    public function test_the_seeder_agrees_with_itself_about_what_is_built(): void
    {
        // PlanSeeder::builtFeatures() is the list the tiers are assembled from.
        // If it drifts from the plans it produces, the docblock is lying.
        $sold = Plan::query()->with('features')->get()
            ->flatMap(fn (Plan $p) => $p->features->where('pivot.is_enabled', true)->pluck('code'))
            ->unique()->sort()->values()->all();

        $claimed = collect(PlanSeeder::builtFeatures())->unique()->sort()->values()->all();

        $this->assertSame($claimed, $sold);
    }

    public function test_a_feature_nothing_gates_is_either_off_everywhere_or_on_everywhere(): void
    {
        /*
         | ⚠️ "Not gated" and "not built" are NOT the same thing, and the
         | difference decides what honest looks like.
         |
         |   Not built  — Loyalty, SMS, Quotations. Nothing exists. Sell it and
         |                the shop pays for a page it never finds. Off, always.
         |   Not gated  — barcode scanning, holding a sale. Built, working, but
         |                no code asks the plan's permission, so EVERY shop has
         |                it whatever the pivot says. Put it on a paid tier and
         |                the matrix tells the cheap tier it lacks something it
         |                is using. On, always.
         |
         | Both come out as "the pivot must be the same in every plan", which is
         | what this checks — the seeder's own docblock names which is which.
         */
        $ungated = array_values(array_diff(FeatureRegistry::codes(), $this->featureCodesTheApplicationGatesOn()));

        // The roadmap is real — there should be things on it.
        $this->assertNotEmpty($ungated);

        $plans = Plan::query()->with('features')->get();

        foreach ($ungated as $code) {
            $states = [];

            foreach ($plans as $plan) {
                $row = $plan->features->firstWhere('code', $code);

                // Present but off, not absent: "not in this plan" and "nobody
                // configured this" must not look the same on the matrix (#84).
                $this->assertNotNull($row, "Plan \"{$plan->name}\" has no row for \"{$code}\".");

                $states[$plan->name] = (bool) $row->pivot->is_enabled;
            }

            $this->assertCount(
                1,
                array_unique($states),
                "\"{$code}\" is not gated anywhere, yet the plans disagree about it: "
                    .json_encode($states).'. A toggle that changes nothing must not be sold as a difference.',
            );
        }
    }

    // ═══════════════════════════════════════════════════════ the ladder

    public function test_each_tier_contains_everything_below_it(): void
    {
        $order = ['free', 'starter', 'professional', 'business'];
        $previous = [];

        foreach ($order as $slug) {
            $codes = $this->enabledCodes($slug);

            foreach ($previous as $code) {
                $this->assertContains($code, $codes, "\"{$slug}\" drops \"{$code}\", which a cheaper plan includes.");
            }

            $previous = $codes;
        }
    }

    public function test_the_top_tier_sells_everything_that_is_built(): void
    {
        $this->assertSame(
            collect(PlanSeeder::builtFeatures())->unique()->sort()->values()->all(),
            collect($this->enabledCodes('business'))->sort()->values()->all(),
        );
    }

    public function test_udhaar_and_khata_are_on_the_bottom_paid_tier(): void
    {
        // ⚠️ Deliberate, and worth defending in a test: credit sales and a
        // customer ledger are how an ordinary shop here does business daily,
        // not advanced accounting. Behind the top tier they would make the
        // cheap tier useless to the shop it is aimed at.
        $starter = $this->enabledCodes('starter');

        $this->assertContains(FeatureRegistry::SALES_CREDIT_SALES, $starter);
        $this->assertContains(FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER, $starter);
        $this->assertContains(FeatureRegistry::CATALOG_MULTI_UNIT, $starter);
    }

    public function test_stock_transfers_need_the_tier_that_has_somewhere_to_transfer_to(): void
    {
        // Warehouses are not built, so branches are the only place stock sits.
        // Transfers below multi-branch would be a button with no destination.
        $this->assertNotContains(FeatureRegistry::INVENTORY_TRANSFERS, $this->enabledCodes('professional'));

        $business = $this->enabledCodes('business');
        $this->assertContains(FeatureRegistry::INVENTORY_TRANSFERS, $business);
        $this->assertContains(FeatureRegistry::BRANCHES_MULTI_BRANCH, $business);
    }

    // ═══════════════════════════════════════════════════════ the money

    public function test_the_prices_climb_with_the_tiers(): void
    {
        $monthly = fn (string $slug) => (float) Plan::query()->where('slug', $slug)->firstOrFail()
            ->prices()->where('billing_cycle', BillingCycle::Monthly->value)->value('price');

        $this->assertSame(0.0, $monthly('free'));
        $this->assertLessThan($monthly('professional'), $monthly('starter'));
        $this->assertLessThan($monthly('business'), $monthly('professional'));
    }

    public function test_a_longer_commitment_is_never_more_expensive_per_month(): void
    {
        // A yearly price above twelve monthlies is not a discount, it is a bug
        // nobody would report — they would just pay monthly and think less of
        // the shop selling it.
        $months = [
            BillingCycle::Monthly->value => 1,
            BillingCycle::Quarterly->value => 3,
            BillingCycle::HalfYearly->value => 6,
            BillingCycle::Yearly->value => 12,
        ];

        foreach (Plan::query()->with('prices')->get() as $plan) {
            $monthly = $plan->prices->firstWhere('billing_cycle', BillingCycle::Monthly->value);

            if ($monthly === null || (float) $monthly->price <= 0) {
                continue;
            }

            foreach ($plan->prices as $price) {
                $span = $months[$price->billing_cycle->value ?? $price->billing_cycle] ?? null;

                if ($span === null || $span === 1) {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    (float) $monthly->price * $span,
                    (float) $price->price,
                    "\"{$plan->name}\" charges more to commit for longer.",
                );
            }
        }
    }

    public function test_the_free_plan_can_actually_run_a_shop_for_a_month(): void
    {
        $free = Plan::query()->where('slug', 'free')->with('limits')->firstOrFail();

        $invoices = (int) $free->limits->firstWhere('code', LimitRegistry::INVOICES_PER_MONTH)->pivot->value;

        // ~10 sales a day. Below that it is a teaser that cannot be judged,
        // and nobody upgrades from something they never got working.
        $this->assertGreaterThanOrEqual(300, $invoices);
    }

    // ═══════════════════════════════════════════════════════════ helpers

    /** @return list<string> */
    protected function enabledCodes(string $slug): array
    {
        return Plan::query()->where('slug', $slug)->with('features')->firstOrFail()
            ->features->where('pivot.is_enabled', true)->pluck('code')->values()->all();
    }

    /**
     * Feature codes the application really checks, found by reading the source
     * for `FeatureRegistry::CONST` / `F::CONST` outside the registry itself.
     *
     * Reading the code rather than keeping a list here is the whole point: a
     * hand-maintained list would go stale the first time somebody built
     * something, and a stale list fails in the direction that hides bugs.
     *
     * @return list<string>
     */
    protected function featureCodesTheApplicationGatesOn(): array
    {
        $constants = [];

        foreach ((new \ReflectionClass(FeatureRegistry::class))->getConstants() as $name => $value) {
            if (is_string($value) && in_array($value, FeatureRegistry::codes(), true)) {
                $constants[$name] = $value;
            }
        }

        $source = '';

        foreach ([base_path('app'), base_path('routes'), base_path('resources/views')] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                    continue;
                }

                // The registry defines the constants; it does not gate on them.
                if ($file->getFilename() === 'FeatureRegistry.php') {
                    continue;
                }

                $source .= file_get_contents($file->getPathname());
            }
        }

        $found = [];

        foreach ($constants as $name => $code) {
            if (preg_match('/(?:FeatureRegistry|F)::'.preg_quote($name, '/').'\b/', $source)) {
                $found[] = $code;
            }
        }

        return $found;
    }
}
