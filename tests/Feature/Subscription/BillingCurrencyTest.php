<?php

namespace Tests\Feature\Subscription;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\PlatformSettingsService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the shop sees on /app/billing, in the operator's money.
 *
 * ⚠️ TWO CURRENCIES LIVE IN THIS APPLICATION and they must never be mixed up:
 *
 *   subscription.currency_*  what KN Softic charges the SHOP  → this screen
 *   format.currency_*        what the shop charges ITS customers → the till
 *
 * And inside the first there are two more: the operator's currency TODAY, and
 * the one snapshotted onto a subscription when it was sold. The snapshot is
 * deliberate — a payment of USD 39 was USD 39 forever, and relabelling history
 * when the operator switches to rupees would be a lie about money.
 *
 * So the screen has to show three cases without confusing anybody:
 *   same as today   → the symbol, "Rs 1,000"
 *   older currency  → the ISO code, "USD 39", never a rupee sign on a dollar
 *   missing         → today's symbol, because a bare number with a space in
 *                     front of it is what "the currency is not showing" means
 */
class BillingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        /*
         | ⚠️ Through the SERVICE, not config(). ApplyPlatformSettings runs on
         | every web request and overlays the operator's settings onto config,
         | so anything set here with config() is wiped before the controller
         | ever sees it -- and the test would then be measuring the shipped
         | default while believing it had chosen something.
         */
        app(PlatformSettingsService::class)->put([
            'subscription.currency' => 'PKR',
            'subscription.currency_symbol' => 'Rs ',
            'subscription.currency_decimals' => 0,
        ]);

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    protected function subscribe(array $attributes = []): Subscription
    {
        $plan = Plan::factory()->monthly(1000)->create(['name' => 'Starter']);

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        $subscription = Subscription::factory()->forBusiness($this->business)->forPlan($plan)
            ->create(array_merge(['price' => 1000, 'currency' => 'PKR'], $attributes));

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->owner->refresh();

        return $subscription;
    }

    public function test_the_billed_amount_carries_the_operators_symbol(): void
    {
        $this->subscribe();

        $this->actingAs($this->owner)->get(route('app.billing.index'))
            ->assertOk()
            ->assertSee('Rs 1,000');
    }

    public function test_an_older_currency_is_named_rather_than_relabelled(): void
    {
        // ⚠️ Sold in dollars, operator now charges in rupees. Printing "Rs 39"
        // would claim the shop was billed 39 rupees, which is false by a factor
        // of about three hundred.
        $this->subscribe(['currency' => 'USD', 'price' => 39]);

        $page = $this->actingAs($this->owner)->get(route('app.billing.index'))->assertOk();

        $page->assertSee('USD 39');
        $page->assertDontSee('Rs 39');
    }

    public function test_a_missing_currency_falls_back_to_todays_symbol(): void
    {
        /*
         | ⚠️ THE ACTUAL "currency show nahi ho rahi" CASE.
         |
         | With no currency on the row, the comparison fails and the code
         | printed the empty string plus a space — so the page showed a bare
         | number with a gap where the money marker should be. Nothing looked
         | broken enough to be an error, and the number was still right, which
         | is the worst kind of wrong on a billing screen.
         */
        $subscription = $this->subscribe();

        // Older rows, and anything imported, can arrive with this blank.
        Subscription::query()->whereKey($subscription->id)->update(['currency' => '']);

        $this->actingAs($this->owner)->get(route('app.billing.index'))
            ->assertOk()
            ->assertSee('Rs 1,000');
    }

    public function test_the_plans_page_and_the_billing_page_agree(): void
    {
        // Two screens, one click apart, both quoting the operator's prices.
        // Disagreeing about the symbol makes the shop distrust both.
        $this->subscribe();

        $this->actingAs($this->owner)->get(route('app.billing.plans'))
            ->assertOk()
            ->assertSee('Rs 1,000', escape: false)
            // Not assertDontSee('$'): Alpine's own markup is full of $el and
            // $wire, so that would be testing the framework, not the money.
            ->assertDontSee('$1,000');
    }

    public function test_the_tills_currency_does_not_leak_into_the_billing_screen(): void
    {
        // The shop sells in rupees with a "₨" of its own choosing; that has
        // nothing to do with what KN Softic charges it.
        $this->subscribe();

        config(['format.currency_symbol' => '₨']);

        $this->actingAs($this->owner)->get(route('app.billing.index'))
            ->assertOk()
            ->assertSee('Rs 1,000')
            ->assertDontSee('₨1,000');
    }
}
