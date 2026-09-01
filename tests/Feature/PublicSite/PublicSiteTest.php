<?php

namespace Tests\Feature\PublicSite;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\PosCounter;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlatformSettingsService;
use App\Services\RegistrationService;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public website, pricing and sign-up (#106–#109).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE PRICING PAGE IS THE PLANS. Nothing on it is typed by hand, so it can
 *     never quote a number the system refuses to charge.
 *  2. A PRIVATE PLAN STAYS PRIVATE. One unticked box is the only thing an
 *     operator should have to remember (#172).
 *  3. SIGN-UP IS ALL OR NOTHING, and produces exactly what the admin console
 *     produces — main branch, till, roles, subscription.
 *  4. THE SWITCH IS REAL. Closed means closed, including for somebody holding a
 *     bookmarked URL (#110).
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);
    }

    protected function platform(): PlatformSettingsService
    {
        return app(PlatformSettingsService::class);
    }

    /** A plan that can actually be sold. */
    protected function plan(string $name, float $monthly, array $overrides = []): Plan
    {
        $plan = Plan::factory()->create(array_merge([
            'name' => $name,
            'is_active' => true,
            'is_public' => true,
            'trial_days' => 14,
        ], $overrides));

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        $plan->prices()->create([
            'billing_cycle' => BillingCycle::Monthly,
            'price' => $monthly,
            'is_active' => true,
        ]);

        return $plan->fresh();
    }

    protected function openRegistration(): void
    {
        $this->platform()->put(['platform.registration_open' => true]);
    }

    // ============================================== the pages render (#106)

    public function test_every_public_page_renders(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Run the shop, not the software');

        foreach (['features', 'pos', 'inventory', 'reports'] as $slug) {
            $this->get(route('page', $slug))->assertOk();
        }

        $this->get(route('pricing'))->assertOk()->assertSee('Pricing');
        $this->get(route('faq'))->assertOk()->assertSee('Questions');
        $this->get(route('contact'))->assertOk()->assertSee('Get in touch');
    }

    public function test_an_unknown_page_is_not_found(): void
    {
        // The route constraint keeps a typo a 404 rather than swallowing every
        // unmatched path in the application.
        $this->get('/pricing-plans')->assertNotFound();
        $this->get('/features-and-things')->assertNotFound();
    }

    public function test_the_public_site_wears_the_operators_branding(): void
    {
        $this->platform()->put([
            'brand.name' => 'Northwind Software',
            'brand.product' => 'Northwind Till',
            'brand.legal_name' => 'Northwind Software Ltd',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Northwind Software')
            ->assertSee('Northwind Till')
            ->assertDontSee('KN Softic');
    }

    public function test_the_public_site_closes_during_maintenance(): void
    {
        $this->platform()->put(['platform.maintenance' => true]);

        // Selling the product to somebody who then cannot sign in would be
        // worse than saying nothing (#160).
        $this->get(route('home'))->assertStatus(503);
        $this->get(route('pricing'))->assertStatus(503);
    }

    // ============================================== pricing is the plans (#108)

    public function test_pricing_is_built_from_the_plans_that_exist(): void
    {
        $this->plan('Starter', 15);
        $this->plan('Professional', 39);

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('Professional')
            ->assertSee('15.00')
            ->assertSee('39.00');
    }

    public function test_a_private_or_inactive_plan_never_reaches_the_website(): void
    {
        $this->plan('Public Plan', 15);
        $this->plan('Negotiated Deal', 5, ['is_public' => false]);
        $this->plan('Retired Plan', 9, ['is_active' => false]);

        $response = $this->get(route('pricing'))->assertOk();

        $response->assertSee('Public Plan');

        // ⚠️ One unticked box is the only thing an operator should have to
        // remember to keep a deal off the website (#172).
        $response->assertDontSee('Negotiated Deal');
        $response->assertDontSee('Retired Plan');
    }

    public function test_a_cycle_a_plan_does_not_sell_says_so(): void
    {
        $plan = $this->plan('Starter', 15);

        $plan->prices()->create([
            'billing_cycle' => BillingCycle::Yearly,
            'price' => 150,
            'is_active' => true,
        ]);

        $monthlyOnly = $this->plan('Basic', 9);

        $response = $this->get(route('pricing'))->assertOk();

        $response->assertSee('150.00');
        // Better than quoting a number nobody can be charged.
        $response->assertSee('Not sold yearly');

        $this->assertNull($monthlyOnly->price(BillingCycle::Yearly));
    }

    public function test_a_catalogue_with_nothing_public_says_so_rather_than_inventing_numbers(): void
    {
        $this->plan('Hidden', 15, ['is_public' => false]);

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Nothing is published yet')
            ->assertSee('Talk to us');
    }

    // ================================================== the switch (#109, #110)

    public function test_sign_up_is_closed_by_default(): void
    {
        $this->plan('Starter', 15);

        $this->assertFalse(app(RegistrationService::class)->isOpen());

        $this->get(route('register'))->assertRedirect(route('pricing'));

        // A bookmarked URL never asked permission, so the write is guarded too.
        $this->post(route('register.store'), $this->validSignup())->assertForbidden();

        $this->assertSame(0, Business::query()->count());
    }

    public function test_sign_up_needs_a_published_plan(): void
    {
        $this->openRegistration();
        $this->plan('Hidden', 15, ['is_public' => false]);

        // The switch is on but there is nothing to put anybody on. Saying so is
        // better than creating an account with no subscription.
        $this->assertFalse(app(RegistrationService::class)->isOpen());
        $this->get(route('register'))->assertRedirect(route('pricing'));
    }

    public function test_a_signed_in_person_is_not_offered_a_second_shop(): void
    {
        $this->openRegistration();
        $plan = $this->plan('Starter', 15);

        $owner = $this->registerOwner();

        $this->actingAs($owner)->get(route('register'))->assertRedirect();
    }

    // ================================================ the sign-up itself (#109)

    public function test_signing_up_builds_a_whole_working_shop(): void
    {
        $this->openRegistration();
        $plan = $this->plan('Starter', 15);

        $response = $this->post(route('register.store'), $this->validSignup());

        $response->assertRedirect(route('app.dashboard'));

        $business = Business::query()->firstOrFail();
        $owner = User::query()->firstOrFail();

        $this->assertSame('Faisal Kiryana Store', $business->name);
        $this->assertSame('faisal-kiryana-store', $business->slug);
        $this->assertSame('active', $business->status);

        // ⚠️ Never mass-assigned from a public form (#132).
        $this->assertSame($business->id, $owner->business_id);
        $this->assertTrue($owner->is_business_owner);
        $this->assertTrue($owner->is_active);

        // Exactly what the admin console produces — main branch, a till, roles.
        $this->assertSame(1, Branch::query()->allTenants()->where('business_id', $business->id)->count());
        $this->assertSame(1, PosCounter::query()->allTenants()->where('business_id', $business->id)->count());
        $this->assertGreaterThan(0, Role::query()->allTenants()->where('business_id', $business->id)->count());

        $subscription = Subscription::query()->allTenants()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);

        // Signed straight in: asking for a password typed ten seconds ago is a
        // step that exists only for the software's convenience.
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_paid_entry_plan_starts_a_trial(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15, ['trial_days' => 21]);

        $this->post(route('register.store'), $this->validSignup());

        $subscription = Subscription::query()->allTenants()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Trial, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertSame(21, (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at->startOfDay()));
    }

    public function test_a_free_entry_plan_is_assigned_rather_than_trialled(): void
    {
        $this->openRegistration();

        // Free = every price is zero. It is the cheapest, so it is the entry.
        $free = $this->plan('Free', 0, ['trial_days' => 0]);
        $this->plan('Starter', 15);

        $this->assertTrue($free->fresh()->isFree());
        $this->assertSame($free->id, app(RegistrationService::class)->trialPlan()?->id);

        $this->post(route('register.store'), $this->validSignup());

        $subscription = Subscription::query()->allTenants()->firstOrFail();

        // A free plan that expires is exactly what "free" is supposed not to
        // do, and the owner would be asked to renew something that costs
        // nothing.
        $this->assertNotSame(SubscriptionStatus::Trial, $subscription->status);
        $this->assertNull($subscription->trial_ends_at);

        // …and the marketing pages must not promise a trial it will not give.
        $this->assertNull(app(RegistrationService::class)->trialDays());
    }

    public function test_a_failed_sign_up_leaves_nothing_behind(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15);

        $this->registerOwner(['email' => 'taken@shop.test']);

        $before = Business::query()->count();

        $this->post(route('register.store'), $this->validSignup(['email' => 'taken@shop.test']))
            ->assertSessionHasErrors('email');

        // The email address is now taken and the person cannot try again — so a
        // half-registered account is worse than a refused one.
        $this->assertSame($before, Business::query()->count());
    }

    public function test_the_form_insists_on_the_things_that_matter(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15);

        $this->post(route('register.store'), $this->validSignup(['business_name' => '']))
            ->assertSessionHasErrors('business_name');

        $this->post(route('register.store'), $this->validSignup([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');

        $this->post(route('register.store'), $this->validSignup([
            'password_confirmation' => 'somethingelse99',
        ]))->assertSessionHasErrors('password');

        // Consent that is assumed is not consent.
        $this->post(route('register.store'), $this->validSignup(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, Business::query()->count());
    }

    public function test_a_public_form_cannot_plant_itself_in_another_business(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15);

        $victim = Business::factory()->create(['name' => 'Somebody Else']);

        $this->post(route('register.store'), $this->validSignup([
            // Hand-crafted extras that must be ignored entirely (#132).
            'business_id' => $victim->id,
            'is_business_owner' => false,
            'status' => 'suspended',
        ]));

        $owner = User::query()->where('email', 'faisal@kiryana.test')->firstOrFail();

        $this->assertNotSame($victim->id, $owner->business_id, 'A public form planted a user in another business.');
        $this->assertTrue($owner->is_business_owner);
        $this->assertSame('active', $owner->business->status);
    }

    public function test_two_shops_with_the_same_name_get_different_slugs(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15);

        $this->post(route('register.store'), $this->validSignup());

        // A slug is a public identifier; two shops sharing one would be a
        // surprise for both of them.
        app(TenantContext::class)->forget();
        auth('web')->logout();

        $this->post(route('register.store'), $this->validSignup(['email' => 'second@kiryana.test']));

        $slugs = Business::query()->pluck('slug')->all();

        $this->assertCount(2, $slugs);
        $this->assertSame(count($slugs), count(array_unique($slugs)));
    }

    public function test_a_new_shop_starts_empty_and_isolated(): void
    {
        $this->openRegistration();
        $this->plan('Starter', 15);

        $stranger = Business::factory()->create(['name' => 'Stranger Shop']);

        $this->post(route('register.store'), $this->validSignup());

        $owner = User::query()->where('email', 'faisal@kiryana.test')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Faisal Kiryana Store')
            ->assertDontSee('Stranger Shop');
    }

    // ------------------------------------------------------------- fixtures

    /** @return array<string, mixed> */
    protected function validSignup(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Faisal Kiryana Store',
            'name' => 'Faisal Iqbal',
            'email' => 'faisal@kiryana.test',
            'phone' => '03211234567',
            'password' => 'shopkeeper99',
            'password_confirmation' => 'shopkeeper99',
            'terms' => '1',
        ], $overrides);
    }

    protected function registerOwner(array $overrides = []): User
    {
        $business = Business::factory()->create(['name' => 'Existing Shop']);

        $owner = User::factory()->for($business)->create(array_merge([
            'is_business_owner' => true,
        ], $overrides));

        return $owner;
    }
}
