<?php

namespace Tests\Feature\Settings;

use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\TaxRateService;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\Format;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The shop's own settings (#57–#60, #153–#157).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE OVERLAY REACHES THE ENGINE. A setting nobody acts on is decoration,
 *     so the important test is that changing cash rounding changes what a sale
 *     actually charges.
 *  2. SETTINGS DO NOT LEAK BETWEEN TENANTS. Config is process-wide; one shop's
 *     currency appearing on another shop's receipt would be the worst bug this
 *     phase could ship.
 *  3. BACK TO DEFAULT IS REAL. A value equal to what the software ships with
 *     stores no row, so a better default still reaches the shop later.
 *  4. TIME IS STORED IN UTC AND SHOWN LOCAL. Changing timezone must not rewrite
 *     a single stored row.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Settings Test Shop', 'timezone' => 'UTC']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    /** @param  array<string, bool>  $features */
    protected function setUpBusiness(array $features = []): void
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        foreach ([
            LimitRegistry::PRODUCTS => 100,
            LimitRegistry::CATEGORIES => 50,
            LimitRegistry::BRANDS => 50,
            LimitRegistry::CUSTOMERS => 50,
            LimitRegistry::SUPPLIERS => 50,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ] as $code => $value) {
            $plan->limits()->attach(Limit::query()->where('code', $code)->firstOrFail()->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();

        $this->actingAs($this->owner);
    }

    protected function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    protected function stocked(float $price = 100): Product
    {
        $product = app(ProductService::class)->create([
            'name' => 'Cola '.fake()->unique()->numerify('###'),
            'type' => ProductType::Standard->value,
            'cost_price' => 40,
            'selling_price' => $price,
        ]);

        app(InventoryService::class)->createMovement([
            'product' => $product,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Purchase,
            'quantity' => 100,
            'unit_cost' => 40,
        ]);

        return $product;
    }

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions): User
    {
        $role = Role::factory()->for($this->business)->withPermissions($permissions)->create();

        return User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    // ============================================== the store and the overlay

    public function test_an_untouched_setting_follows_the_shipped_default(): void
    {
        $this->setUpBusiness();

        $this->assertSame(config('pos.invoice.prefix'), $this->settings()->get('pos.invoice.prefix'));
        $this->assertSame([], $this->settings()->customised(), 'A new shop has changed nothing.');
        $this->assertSame(0, Setting::query()->allTenants()->count(), 'And stores no rows for it.');
    }

    public function test_saving_a_setting_overlays_the_config_everything_else_reads(): void
    {
        $this->setUpBusiness();

        $this->settings()->put([
            'format.currency_symbol' => '₨',
            'format.decimals' => 0,
        ]);

        // The whole design in one assertion: nothing asked the settings table.
        $this->assertSame('₨', config('format.currency_symbol'));
        $this->assertSame(0, config('format.decimals'));
        $this->assertSame('₨ 1,235', Format::money(1234.56, true));
    }

    public function test_a_value_equal_to_the_default_stores_no_row(): void
    {
        $this->setUpBusiness();

        $this->settings()->put(['format.decimals' => 0]);
        $this->assertSame(['format.decimals'], $this->settings()->customised());

        // Back to what the software ships with — the row goes, so a better
        // default shipped later still reaches this shop.
        $this->settings()->put(['format.decimals' => config('format.decimals') === 0 ? 2 : 2]);

        $this->assertSame([], $this->settings()->customised());
        $this->assertSame(0, Setting::query()->allTenants()->count());
    }

    public function test_a_group_can_be_put_back_to_the_defaults(): void
    {
        $this->setUpBusiness();

        $this->settings()->put([
            'pos.receipt.footer' => 'Come again',
            'pos.receipt.show_qr' => true,
        ]);

        $this->actingAs($this->owner)
            ->post(route('app.settings.reset', 'receipt'))
            ->assertRedirect();

        $this->assertSame([], $this->settings()->customised());
        $this->assertSame(config('pos.receipt.footer'), $this->settings()->get('pos.receipt.footer'));
    }

    public function test_the_setting_reaches_the_sale_engine(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 99.90);

        // Nothing rounded yet.
        $before = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => 1],
        ], [['method' => 'cash', 'amount' => 1000]]);

        $this->assertSame(99.90, (float) $before->total);

        // The shop moves to a currency with no small coin.
        $this->settings()->put(['pos.cash_rounding' => 1]);

        $after = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => 1],
        ], [['method' => 'cash', 'amount' => 1000]]);

        $this->assertSame(100.0, (float) $after->total, 'A setting nobody acts on is decoration.');
        $this->assertSame(0.10, round((float) $after->rounding, 2));
    }

    public function test_settings_do_not_leak_from_one_shop_to_another(): void
    {
        $this->setUpBusiness();

        $this->settings()->put(['format.currency_symbol' => '₹', 'pos.cash_rounding' => 5]);
        $this->assertSame('₹', config('format.currency_symbol'));

        // A second shop, built with the tenant stamp out of the way.
        app(TenantContext::class)->forget();

        $other = Business::factory()->create(['name' => 'Somebody Else']);
        $otherOwner = User::factory()->for($other)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();
        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }
        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }
        Subscription::factory()->forBusiness($other)->forPlan($plan)->create();
        app(OrganizationProvisioner::class)->provision($other);

        // Config is process-wide, so this is the assertion that matters most in
        // the whole phase: the second shop must see the SHIPPED defaults.
        app(TenantContext::class)->setBusiness($other);
        app(SettingsService::class)->apply($other);

        $this->assertSame('Rs', config('format.currency_symbol'));
        $this->assertSame(0.0, (float) config('pos.cash_rounding'));

        // …and coming back restores the first shop's.
        app(TenantContext::class)->setBusiness($this->business);
        app(SettingsService::class)->apply($this->business);

        $this->assertSame('₹', config('format.currency_symbol'));
    }

    public function test_run_for_puts_the_config_back_when_it_is_done(): void
    {
        $this->setUpBusiness();
        $this->settings()->put(['format.currency_symbol' => '€']);

        app(TenantContext::class)->forget();
        $other = Business::factory()->create(['name' => 'Elsewhere']);
        app(TenantContext::class)->setBusiness($this->business);
        app(SettingsService::class)->apply($this->business);

        $seenInside = app(TenantContext::class)->runFor($other, fn () => config('format.currency_symbol'));

        $this->assertSame('Rs', $seenInside, 'The other shop got the defaults.');
        $this->assertSame('€', config('format.currency_symbol'), 'And this one got its own back.');
    }

    // ================================================================ guards

    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        $this->setUpBusiness();

        try {
            // 250% would be checked by nothing else — the till would simply
            // start giving the shop's stock away.
            $this->settings()->put(['sales.max_discount_percent' => 250]);
            $this->fail('A discount ceiling above 100% should be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Setting::query()->allTenants()->count());
    }

    public function test_an_unknown_setting_is_refused(): void
    {
        $this->setUpBusiness();

        $this->expectException(NotFoundHttpException::class);

        $this->settings()->put(['pos.make_me_rich' => true]);
    }

    public function test_a_setting_the_plan_does_not_include_is_not_offered(): void
    {
        $this->setUpBusiness([FeatureRegistry::SALES_DISCOUNTS => false]);

        $response = $this->actingAs($this->owner)->get(route('app.settings.show', 'sales'));

        $response->assertOk();
        $response->assertDontSee('Most anyone may discount');

        // …and a hand-crafted post cannot set it either.
        $this->actingAs($this->owner)->put(route('app.settings.update', 'sales'), [
            'pos__invoice__prefix' => 'INV',
            'pos__invoice__format' => '{PREFIX}-{SEQ:5}',
            'pos__invoice__sequence_scope' => 'business',
            'pos__cash_rounding' => 0,
            'pos__hold_expiry_hours' => 24,
            'sales__max_discount_percent' => 5,
        ])->assertRedirect();

        $this->assertNotContains('sales.max_discount_percent', $this->settings()->customised());
    }

    public function test_settings_need_the_settings_permission(): void
    {
        $this->setUpBusiness();

        $manager = $this->userWith([PermissionRegistry::SALES_VIEW, PermissionRegistry::REPORTS_VIEW]);

        $this->actingAs($manager)->get(route('app.settings.show', 'sales'))->assertRedirect();
        $this->actingAs($manager)
            ->put(route('app.settings.update', 'receipt'), ['pos__receipt__footer' => 'Hacked'])
            ->assertRedirect()
            ->assertSessionHas('permission_denied');

        $this->assertSame([], $this->settings()->customised());
    }

    // ====================================================== the business record

    public function test_the_business_details_are_saved_with_their_logo(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->put(route('app.settings.business'), [
            'name' => 'Renamed Shop',
            'email' => 'hello@renamed.test',
            'phone' => '03001234567',
            'address' => "12 Market Road\nLahore",
            'timezone' => 'Asia/Karachi',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();

        $business = $this->business->fresh();

        $this->assertSame('Renamed Shop', $business->name);
        $this->assertSame('Asia/Karachi', $business->timezone);
        $this->assertNotNull($business->logo_path);
        Storage::disk('public')->assertExists($business->logo_path);
    }

    public function test_a_new_logo_replaces_the_old_one(): void
    {
        $this->setUpBusiness();

        $base = ['name' => 'Shop', 'timezone' => 'UTC'];

        $this->actingAs($this->owner)->put(route('app.settings.business'), $base + [
            'logo' => UploadedFile::fake()->image('first.png'),
        ]);

        $first = $this->business->fresh()->logo_path;

        $this->actingAs($this->owner)->put(route('app.settings.business'), $base + [
            'logo' => UploadedFile::fake()->image('second.png'),
        ]);

        $second = $this->business->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->put(route('app.settings.business'), ['name' => 'Shop', 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    // ==================================== time is stored UTC, shown local (#153)

    public function test_changing_timezone_changes_the_display_and_not_the_storage(): void
    {
        $this->setUpBusiness();

        $stamp = Carbon::parse('2026-09-01 06:30:00', 'UTC');

        $this->business->update(['timezone' => 'UTC']);
        app(TenantContext::class)->setBusiness($this->business->fresh());
        $this->assertSame('06:30', Format::time($stamp));

        $this->business->update(['timezone' => 'Asia/Karachi']);
        app(TenantContext::class)->setBusiness($this->business->fresh());

        $this->assertSame('11:30', Format::time($stamp), 'Five hours ahead of UTC.');
        $this->assertSame('2026-09-01 06:30:00', $stamp->utc()->toDateTimeString(), 'The stored instant never moved.');
    }

    public function test_the_date_format_setting_is_what_screens_use(): void
    {
        $this->setUpBusiness();

        $this->settings()->put(['format.date' => 'Y-m-d']);

        $this->assertSame('2026-09-01', Format::date(Carbon::parse('2026-09-01 09:00:00', 'UTC')));
    }

    // ============================================================= taxes (#59)

    public function test_tax_rates_are_named_and_exactly_one_is_the_default(): void
    {
        $this->setUpBusiness();

        $standard = app(TaxRateService::class)->create(['name' => 'Standard', 'rate' => 17, 'is_default' => true]);
        $reduced = app(TaxRateService::class)->create(['name' => 'Reduced', 'rate' => 5]);

        $this->assertSame('Standard — 17%', $standard->label());
        $this->assertTrue($standard->fresh()->is_default);
        $this->assertFalse($reduced->fresh()->is_default);

        // Moving the default has to clear the old one, and "exactly one" is a
        // transaction rather than a constraint.
        app(TaxRateService::class)->update($reduced, ['is_default' => true]);

        $this->assertFalse($standard->fresh()->is_default);
        $this->assertTrue($reduced->fresh()->is_default);
        $this->assertSame(1, TaxRate::query()->where('is_default', true)->count());
    }

    public function test_switching_a_rate_off_also_stops_it_being_the_default(): void
    {
        $this->setUpBusiness();

        $rate = app(TaxRateService::class)->create(['name' => 'Standard', 'rate' => 17, 'is_default' => true]);

        app(TaxRateService::class)->update($rate, ['is_active' => false]);

        $this->assertFalse($rate->fresh()->is_active);
        $this->assertFalse($rate->fresh()->is_default, 'A rate nobody may pick cannot be the one offered first.');
    }

    public function test_a_rate_above_a_hundred_percent_is_refused(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)
            ->post(route('app.tax-rates.store'), ['name' => 'Silly', 'rate' => 150, 'is_active' => 1])
            ->assertSessionHasErrors('rate');

        $this->assertSame(0, TaxRate::query()->count());
    }

    public function test_two_rates_cannot_share_a_name(): void
    {
        $this->setUpBusiness();

        app(TaxRateService::class)->create(['name' => 'Standard', 'rate' => 17]);

        $this->actingAs($this->owner)
            ->post(route('app.tax-rates.store'), ['name' => 'Standard', 'rate' => 5, 'is_active' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_removing_a_rate_does_not_restate_a_sale(): void
    {
        $this->setUpBusiness();

        $rate = app(TaxRateService::class)->create(['name' => 'Standard', 'rate' => 17]);
        $product = $this->stocked(price: 100);

        $sale = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => 1, 'tax_rate' => 17],
        ], [['method' => 'cash', 'amount' => 1000]]);

        $this->assertSame(17.0, (float) $sale->items->first()->tax_rate);

        app(TaxRateService::class)->delete($rate);

        // The line holds the NUMBER, not a link — which is the whole reason a
        // rate can be removed at all.
        $this->assertSame(17.0, (float) $sale->fresh()->items->first()->tax_rate);
        $this->assertSame(117.0, (float) $sale->fresh()->total);
    }

    // ======================================================== discounts (#60)

    public function test_a_person_without_their_own_cap_falls_back_to_the_shops(): void
    {
        $this->setUpBusiness();

        $cashier = $this->userWith([PermissionRegistry::POS_OPERATE]);

        // No shop ceiling and no personal one means no limit.
        $this->assertNull($cashier->discountCap());

        $this->settings()->put(['sales.max_discount_percent' => 10]);

        $this->assertSame(10.0, $cashier->fresh()->discountCap());
        $this->assertTrue($cashier->fresh()->mayDiscount(9));
        $this->assertFalse($cashier->fresh()->mayDiscount(15));

        // An owner is never capped by a shop setting — they set it.
        $this->assertNull($this->owner->discountCap());
    }

    // ========================================================== receipt (#57)

    public function test_the_receipt_follows_its_settings(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 100);

        $sale = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => 2],
        ], [['method' => 'cash', 'amount' => 1000]]);

        // Off by default: no QR, no header line, no tax number.
        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertDontSee('Scan to check this receipt')
            ->assertDontSee('Tax No:');

        $this->settings()->put([
            'pos.receipt.show_qr' => true,
            'pos.receipt.header' => 'Fresh every day',
            'pos.receipt.tax_number' => 'NTN 1234567-8',
            'pos.receipt.footer' => 'Come again',
        ]);

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertSee('Fresh every day')
            ->assertSee('NTN 1234567-8')
            ->assertSee('Come again')
            ->assertSee('Scan to check this receipt')
            ->assertSee('<svg', false);
    }

    public function test_the_receipt_writes_money_the_way_the_shop_does(): void
    {
        $this->setUpBusiness();
        $product = $this->stocked(price: 1000);

        $sale = app(SaleService::class)->complete([], [
            ['product_id' => $product->id, 'quantity' => 2],
        ], [['method' => 'cash', 'amount' => 5000]]);

        $this->settings()->put([
            'format.currency_symbol' => 'AED',
            'format.currency_position' => 'after',
            'format.decimals' => 0,
        ]);

        $this->actingAs($this->owner)
            ->get(route('app.sales.receipt', $sale))
            ->assertOk()
            ->assertSee('2,000 AED');
    }
}
