<?php

namespace Tests\Feature\Settings;

use App\Models\Admin;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\PlatformSettingsService;
use App\Services\SettingsService;
use App\Support\BranchContext;
use App\Support\PlatformSettingRegistry;
use App\Support\SettingRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There are two currencies here, and confusing them would be expensive.
 *
 *   THE OPERATOR'S  what KN Softic charges a shop — the figure on the pricing
 *                   page and on a subscription invoice. `subscription.currency`,
 *                   set once by the super admin in /admin/settings.
 *
 *   THE SHOP'S      what a shop charges its own customers — every product
 *                   price, every receipt, every report. `format.currency_*`,
 *                   set by each shop in its own Settings.
 *
 * A shop in Lahore can bill its customers in PKR while paying us in USD, and
 * both are correct at the same time. Nothing may leak between them: showing a
 * shopkeeper their own takings in OUR currency, or invoicing them in theirs,
 * are both real money errors.
 */
class TwoCurrenciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_can_set_the_billing_currency_without_ssh(): void
    {
        // It used to live only in .env, which meant changing what you charge in
        // required a developer and a deploy.
        $this->assertTrue(PlatformSettingRegistry::exists('subscription.currency'));
        $this->assertTrue(PlatformSettingRegistry::exists('subscription.currency_symbol'));

        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.show', 'billing'))
            ->assertOk()
            ->assertSee('Billing currency');
    }

    public function test_saving_it_reaches_the_config_every_price_is_read_from(): void
    {
        app(PlatformSettingsService::class)->put([
            'subscription.currency' => 'PKR',
            'subscription.currency_symbol' => 'Rs',
        ]);

        $this->assertSame('PKR', config('subscription.currency'));
        $this->assertSame('Rs', config('subscription.currency_symbol'));
    }

    public function test_a_shop_sets_its_own_selling_currency_and_ours_does_not_move(): void
    {
        app(PlatformSettingsService::class)->put([
            'subscription.currency' => 'USD',
            'subscription.currency_symbol' => '$',
        ]);

        $business = Business::factory()->create();
        User::factory()->for($business)->create(['is_business_owner' => true]);

        app(TenantContext::class)->setBusiness($business);

        $this->assertTrue(SettingRegistry::exists('format.currency_symbol'));

        app(SettingsService::class)->put([
            'format.currency_code' => 'PKR',
            'format.currency_symbol' => 'Rs',
        ]);

        // ⚠️ THE WHOLE POINT. The shop sells in rupees; we still bill in
        // dollars. Neither setting may drag the other with it.
        $this->assertSame('Rs', config('format.currency_symbol'));
        $this->assertSame('$', config('subscription.currency_symbol'), 'A shop changing its own currency must not change what we charge in.');
    }

    public function test_the_till_prints_the_shops_money_not_ours(): void
    {
        /*
         | ⚠️ THE BUG THIS PINS. PosController passed
         | `subscription.currency_symbol` to the till — what WE charge the shop
         | — so a Lahore shop taking rupees saw a dollar sign on every line,
         | every total and every receipt preview.
         |
         | The cart lives in the browser, so the whole format goes over, not
         | just the symbol: position, decimals and both separators are shop
         | settings too.
         */
        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        app(PlatformSettingsService::class)->put(['subscription.currency_symbol' => '$']);

        $business = Business::factory()->create();
        $owner = User::factory()->for($business)->create(['is_business_owner' => true]);

        // The till sits behind the subscription gate and the POS feature, so
        // reaching it at all needs a real plan.
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        Subscription::factory()->forBusiness($business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($business);
        app(TenantContext::class)->setBusiness($business);
        app(BranchContext::class)->forUser($owner);
        $owner->refresh();

        app(SettingsService::class)->put([
            'format.currency_symbol' => 'Rs',
            'format.decimals' => 2,
        ]);

        $response = $this->actingAs($owner)->get(route('app.pos.index'))->assertOk();

        // The view data rather than the HTML: `@js()` encodes every quote as
        // ", and an assertion that had to know that would break the day
        // Blade changed its encoder without the till changing at all.
        $money = $response->viewData('money');

        $this->assertSame('Rs', $money['symbol'], "The till must be handed the shop's own symbol.");
        $this->assertNotSame('$', $money['symbol'], "Our billing currency has no business on a shop's till.");

        // The symbol alone was never the whole story: a till that honoured it
        // and nothing else is still wrong for anyone who does not write
        // 1,234.56.
        $this->assertSame(['symbol', 'position', 'decimals', 'thousands', 'decimal'], array_keys($money));
    }

    public function test_one_shops_currency_never_reaches_another(): void
    {
        $lahore = Business::factory()->create(['name' => 'Lahore Shop']);
        $dubai = Business::factory()->create(['name' => 'Dubai Shop']);

        $tenant = app(TenantContext::class);

        $tenant->runFor($lahore, fn () => app(SettingsService::class)->put(['format.currency_symbol' => 'Rs']));
        $tenant->runFor($dubai, fn () => app(SettingsService::class)->put(['format.currency_symbol' => 'د.إ']));

        // config is PROCESS-wide, so the overlay has to be re-applied and
        // restored per tenant. Printing one shop's receipt in another shop's
        // currency is the worst bug this design could ship.
        $tenant->runFor($lahore, function (): void {
            $this->assertSame('Rs', config('format.currency_symbol'));
        });

        $tenant->runFor($dubai, function (): void {
            $this->assertSame('د.إ', config('format.currency_symbol'));
        });
    }
}
