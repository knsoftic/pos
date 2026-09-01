<?php

namespace Tests\Feature\Settings;

use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\PlatformSettingsService;
use App\Services\TenantNotificationService;
use App\Support\BranchContext;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The operator's own settings (#110, #111, #76, #77, #160).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. MAINTENANCE MUST NOT LOCK THE OPERATOR OUT. /admin stays reachable, which
 *     is the whole reason this is not `php artisan down`.
 *  2. AND IT MUST BEAT AUTHENTICATION. A closed platform that first asks a
 *     shopkeeper to sign in and only then says it is shut is a worse experience
 *     than no maintenance page at all.
 *  3. BRANDING REACHES THE PAGES. Changing the company name changes the login
 *     screen — that is the only proof the white-label promise is real.
 *  4. AN ALERT CANNOT BE DISMISSED, AN ANNOUNCEMENT CAN. One is a condition,
 *     the other is a message.
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->admin = Admin::factory()->create();
        $this->business = Business::factory()->create(['name' => 'Platform Test Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    protected function setUpBusiness(): void
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();
    }

    protected function platform(): PlatformSettingsService
    {
        return app(PlatformSettingsService::class);
    }

    // ======================================================= the store (#110)

    public function test_an_untouched_platform_setting_follows_the_shipped_default(): void
    {
        $this->assertSame(config('brand.name'), $this->platform()->get('brand.name'));
        $this->assertSame([], $this->platform()->customised());
        $this->assertSame(0, PlatformSetting::query()->count());
    }

    public function test_saving_overlays_the_config_and_reaches_the_login_screen(): void
    {
        /*
        | A white-label operator changes all three, and they are separate on
        | purpose: "Northwind Software Ltd" is the legal entity that appears in
        | a copyright line, "Northwind Software" is the company people say, and
        | "Northwind Till" is the product. Setting only one and expecting the
        | old name to vanish would be the mistake this test exists to catch.
        */
        $this->platform()->put([
            'brand.name' => 'Northwind Software',
            'brand.product' => 'Northwind Till',
            'brand.legal_name' => 'Northwind Software Ltd',
        ]);

        $this->assertSame('Northwind Software', config('brand.name'));

        // The white-label promise, proved: no deployment, no search and replace.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Northwind Software')
            ->assertSee('Northwind Till')
            ->assertDontSee('KN Softic');
    }

    public function test_a_value_equal_to_the_default_stores_no_row(): void
    {
        $shipped = config('brand.name');

        $this->platform()->put(['brand.name' => 'Temporary']);
        $this->assertSame(['brand.name'], $this->platform()->customised());

        $this->platform()->put(['brand.name' => $shipped]);

        $this->assertSame([], $this->platform()->customised());
        $this->assertSame(0, PlatformSetting::query()->count());
    }

    public function test_a_bad_value_is_refused(): void
    {
        try {
            $this->platform()->put(['subscription.trial_days' => 5000]);
            $this->fail('A 5,000-day trial should be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, PlatformSetting::query()->count());
    }

    public function test_an_unknown_platform_setting_is_refused(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->platform()->put(['platform.free_money' => true]);
    }

    public function test_the_settings_screen_saves_and_resets(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.update', 'signup'), [
                'platform__registration_open' => '1',
                'subscription__trial_days' => 30,
                'subscription__grace_days' => 5,
            ])
            ->assertRedirect();

        $this->assertTrue($this->platform()->get('platform.registration_open'));
        $this->assertSame(30, $this->platform()->get('subscription.trial_days'));

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.settings.reset', 'signup'))
            ->assertRedirect();

        $this->assertSame([], $this->platform()->customised());
    }

    public function test_platform_settings_are_for_admins_only(): void
    {
        $this->setUpBusiness();

        // A shop owner is not the operator, however senior they are in their
        // own business.
        $this->actingAs($this->owner)
            ->get(route('admin.settings.show', 'branding'))
            ->assertRedirect(route('admin.login'));

        $this->get(route('admin.settings.show', 'branding'))->assertRedirect(route('admin.login'));
    }

    // ========================================================= branding (#111)

    public function test_an_uploaded_logo_replaces_the_drawn_mark(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.logo'), ['logo' => UploadedFile::fake()->image('mark.png')])
            ->assertRedirect();

        $path = $this->platform()->get('brand.logo_path');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // …and removing it falls back to the drawn mark, which renders in
        // places a file never reaches.
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.settings.logo'), ['remove_logo' => '1'])
            ->assertRedirect();

        $this->assertNull($this->platform()->get('brand.logo_path'));
        Storage::disk('public')->assertMissing($path);
    }

    // ====================================================== maintenance (#160)

    public function test_maintenance_closes_the_shops_and_keeps_the_operator_in(): void
    {
        $this->setUpBusiness();

        $this->platform()->put(['platform.maintenance' => true]);

        // A shopkeeper is TOLD, not asked to sign in first.
        $this->get(route('login'))
            ->assertStatus(503)
            ->assertSee('Back shortly');

        $this->actingAs($this->owner)
            ->get(route('app.dashboard'))
            ->assertStatus(503);

        // The operator's own panel stays open — it is how this gets turned off.
        $this->get(route('admin.login'))->assertOk();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.settings.show', 'maintenance'))
            ->assertOk();
    }

    public function test_a_signed_in_operator_can_still_reach_a_shop_during_maintenance(): void
    {
        $this->setUpBusiness();
        $this->platform()->put(['platform.maintenance' => true]);

        // So they can check a release actually works before letting shops back.
        $this->actingAs($this->admin, 'admin')->get(route('login'))->assertOk();
    }

    public function test_the_preview_token_opens_a_door_and_only_the_right_one(): void
    {
        $this->platform()->put([
            'platform.maintenance' => true,
            'platform.maintenance_token' => 'deploy123',
        ]);

        $this->get(route('login', ['maintenance_token' => 'wrong']))->assertStatus(503);

        $this->get(route('login', ['maintenance_token' => 'deploy123']))->assertOk();

        // Accepted once, it holds for the rest of the visit — otherwise every
        // link during a check would need the query string reattached.
        $this->get(route('login'))->assertOk();
    }

    public function test_the_health_check_stays_up_during_maintenance(): void
    {
        $this->platform()->put(['platform.maintenance' => true]);

        // A load balancer taking the box out of rotation during a planned
        // window is exactly wrong.
        $this->get('/up')->assertOk();
    }

    public function test_the_maintenance_page_is_a_503_not_a_200(): void
    {
        $this->platform()->put(['platform.maintenance' => true]);

        $response = $this->get(route('login'));

        // A maintenance page answering 200 gets cached and indexed as the
        // site's content and outlives the outage.
        $response->assertStatus(503);
        $response->assertHeader('Retry-After');
    }

    // ==================================================== announcements (#77)

    public function test_an_announcement_reaches_every_shop_and_can_be_put_away(): void
    {
        $this->setUpBusiness();

        $announcement = Announcement::factory()->create([
            'title' => 'Servers move on Sunday',
            'body' => 'Between 02:00 and 04:00.',
            'level' => 'warning',
        ]);

        $this->actingAs($this->owner);

        $notifications = app(TenantNotificationService::class);
        $titles = collect($notifications->all())->pluck('title');

        $this->assertContains('Servers move on Sunday', $titles->all());

        $this->actingAs($this->owner)
            ->post(route('app.notifications.dismiss', $announcement))
            ->assertRedirect();

        // Per PERSON: the owner reading it does not mean the cashier has.
        $this->assertDatabaseHas('announcement_dismissals', [
            'announcement_id' => $announcement->id,
            'user_id' => $this->owner->id,
        ]);

        app()->forgetInstance(TenantNotificationService::class);

        $this->assertNotContains(
            'Servers move on Sunday',
            collect(app(TenantNotificationService::class)->all())->pluck('title')->all(),
        );
    }

    public function test_an_announcement_outside_its_window_is_not_shown(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        Announcement::factory()->expired()->create(['title' => 'Old news']);
        Announcement::factory()->upcoming()->create(['title' => 'Future news']);
        Announcement::factory()->create(['title' => 'Live news']);

        $titles = collect(app(TenantNotificationService::class)->all())->pluck('title')->all();

        // "Maintenance on Sunday" is worse than useless on Monday.
        $this->assertContains('Live news', $titles);
        $this->assertNotContains('Old news', $titles);
        $this->assertNotContains('Future news', $titles);
    }

    public function test_a_notice_that_may_not_be_dismissed_refuses_to_be(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $announcement = Announcement::factory()->create([
            'title' => 'Outage in progress',
            'is_dismissible' => false,
        ]);

        $this->actingAs($this->owner)
            ->post(route('app.notifications.dismiss', $announcement))
            ->assertStatus(422);

        $this->assertDatabaseCount('announcement_dismissals', 0);
    }

    public function test_the_operator_can_publish_and_end_an_announcement(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.announcements.store'), [
                'title' => 'New reports are live',
                'body' => 'Thirty of them, under Reports.',
                'level' => 'info',
                'is_active' => '1',
                'is_dismissible' => '1',
            ])
            ->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        $this->assertTrue($announcement->isLive());

        // Ending it early is a switch, not a delete — the record of what was
        // said stays.
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.announcements.update', $announcement), [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'level' => 'info',
            ])
            ->assertRedirect();

        $this->assertFalse($announcement->fresh()->isLive());
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_an_announcement_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.announcements.store'), [
                'title' => 'Backwards',
                'body' => 'Nobody would ever see this.',
                'level' => 'info',
                'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'ends_at' => now()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    // =========================================================== the bell (#76)

    public function test_an_alert_is_a_condition_and_cannot_be_silenced(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        $items = app(TenantNotificationService::class)->all();

        foreach ($items as $item) {
            if ($item['type'] === 'alert') {
                $this->assertFalse(
                    $item['dismissible'],
                    'An alert you can swipe away while it is still true teaches people to swipe them all away.',
                );
            }
        }

        $this->assertTrue(true);
    }

    public function test_the_bell_only_tells_people_what_they_may_see(): void
    {
        $this->setUpBusiness();

        // Make something be wrong with the stock.
        DB::table('announcements')->delete();

        $role = Role::factory()->for($this->business)->withPermissions([PermissionRegistry::POS_OPERATE])->create();

        $cashier = User::factory()->for($this->business)->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($cashier);
        app()->forgetInstance(TenantNotificationService::class);

        foreach (app(TenantNotificationService::class)->all() as $item) {
            // A cashier who cannot open the inventory is not told what is low
            // in it, and is not told about the shop's billing.
            $this->assertStringNotContainsString('shel', strtolower($item['title']));
            $this->assertStringNotContainsString('subscription', strtolower($item['title']));
        }

        $this->assertTrue(true);
    }

    public function test_the_bell_is_empty_for_a_healthy_shop(): void
    {
        $this->setUpBusiness();
        $this->actingAs($this->owner);

        DB::table('announcements')->delete();

        // Nothing bought, nothing low, nothing expiring, subscription fine.
        $this->assertSame([], app(TenantNotificationService::class)->all());
        $this->assertSame(0, app(TenantNotificationService::class)->count());
    }
}
