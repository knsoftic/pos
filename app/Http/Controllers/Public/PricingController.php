<?php

namespace App\Http\Controllers\Public;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\RegistrationService;
use App\Support\FeatureRegistry;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Pricing, built from the plans that actually exist (#108).
 *
 * ================= NOTHING HERE IS WRITTEN BY HAND =================
 * The cards, the prices and the feature ticks all come from the `plans` table.
 * A pricing page maintained separately from the plans it sells is a pricing
 * page that will one day quote a number the system refuses to charge — and the
 * customer who spotted it is right.
 *
 * ⚠️ Only ACTIVE and PUBLIC plans (#172). A plan the operator uses for a
 * negotiated deal or a legacy customer stays off the website by unticking one
 * box, and that has to be the only thing they need to remember.
 */
class PricingController extends Controller
{
    public function __construct(protected RegistrationService $registration) {}

    public function index(): View
    {
        $plans = Plan::query()
            ->active()
            ->public()
            ->ordered()
            ->with(['prices', 'features', 'limits'])
            ->get();

        // The cycles anyone actually has a price for — no empty "Yearly" tab on
        // a catalogue that only sells monthly.
        $cycles = collect(BillingCycle::cases())
            ->filter(fn (BillingCycle $cycle) => $plans->contains(fn (Plan $plan) => $plan->price($cycle) !== null))
            ->values();

        return view('public.pricing', [
            'plans' => $plans,
            'cycles' => $cycles,
            'comparison' => $this->comparison($plans),
            'canRegister' => $this->registration->isOpen(),
            'trialPlan' => $this->registration->trialPlan(),
        ]);
    }

    /**
     * The comparison table: the features worth deciding on, per plan.
     *
     * Deliberately not every feature in the registry — a table of sixty rows is
     * a table nobody reads. These are the ones that change what a shop can do.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array<string, array<string, mixed>>
     */
    protected function comparison($plans): array
    {
        $codes = [
            FeatureRegistry::POS_TERMINAL,
            FeatureRegistry::SALES_RETURNS,
            FeatureRegistry::INVENTORY_STOCK_TRACKING,
            FeatureRegistry::INVENTORY_TRANSFERS,
            FeatureRegistry::INVENTORY_EXPIRY_TRACKING,
            FeatureRegistry::PURCHASES_ORDERS,
            FeatureRegistry::ACCOUNTING_CUSTOMER_LEDGER,
            FeatureRegistry::ACCOUNTING_EXPENSES,
            FeatureRegistry::ACCOUNTING_PROFIT_LOSS,
            FeatureRegistry::REPORTS_BASIC,
            FeatureRegistry::REPORTS_ADVANCED,
            FeatureRegistry::REPORTS_EXPORT_PDF,
            FeatureRegistry::TEAM_MULTI_USER,
            FeatureRegistry::TEAM_ROLES,
            FeatureRegistry::BRANCHES_MULTI_BRANCH,
        ];

        $rows = [];

        foreach ($codes as $code) {
            if (! FeatureRegistry::exists($code)) {
                continue;
            }

            $rows[$code] = [
                'name' => FeatureRegistry::all()[$code]['name'],
                'plans' => $plans->mapWithKeys(fn (Plan $plan) => [
                    $plan->id => (bool) $plan->features->firstWhere('code', $code)?->pivot?->is_enabled,
                ])->all(),
            ];
        }

        return $rows;
    }
}
