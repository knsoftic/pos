<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Login / logout for BOTH guards, and the hard separation between them. #62 / #64
 *
 * The two panels must behave like two different applications that happen to
 * share a codebase: an operator session can never act as a business user, and
 * vice versa.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['name' => 'Acme Traders']);
        $this->user = User::factory()->for($this->business)->create([
            'email' => 'owner@acme.test',
            'password' => 'Password123',
            'is_business_owner' => true,
        ]);
    }

    // ------------------------------------------------------- business (web)

    public function test_the_root_url_is_the_marketing_site_with_a_way_in(): void
    {
        /*
        | ⚠️ This changed in Phase 12 and changed correctly. The root used to
        | redirect to the login screen because there was no website yet;
        | now it IS the website, and bouncing a prospective customer straight to
        | a login form would be the wrong thing for the one page most visitors
        | see first (#106, #107).
        */
        $this->get('/')
            ->assertOk()
            ->assertSee(route('login'), false);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Forgot password?', false);
    }

    public function test_business_user_can_authenticate_and_lands_on_the_app_dashboard(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ]);

        $response->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_successful_login_records_last_login_at(): void
    {
        $this->assertNull($this->user->last_login_at);

        $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ]);

        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->post(route('login.store'), [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest('web');
    }

    public function test_deactivated_user_cannot_authenticate_even_with_correct_password(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_user_of_a_suspended_business_is_bounced_out_of_the_app(): void
    {
        $this->business->update(['status' => Business::STATUS_SUSPENDED]);

        // Credentials are valid, so login itself succeeds …
        $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ])->assertRedirect(route('app.dashboard'));

        // … but the tenant gate refuses to open a session for a suspended tenant.
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_the_configured_attempts(): void
    {
        $max = (int) config('security.throttle.login_max_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->post(route('login.store'), [
                'email' => 'owner@acme.test',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123', // correct now — must still be blocked
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) session('errors')->first('email')
        );
        $this->assertGuest('web');

        RateLimiter::clear('owner@acme.test|127.0.0.1|web');
    }

    public function test_authenticated_user_visiting_login_is_redirected_to_the_app(): void
    {
        $this->actingAs($this->user, 'web')
            ->get(route('login'))
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_business_user_can_logout(): void
    {
        $this->actingAs($this->user, 'web')
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_guests_cannot_reach_the_app_dashboard(): void
    {
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
    }

    // --------------------------------------------------------- admin (super)

    public function test_admin_login_screen_can_be_rendered(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('SaaS operator console');
    }

    public function test_super_admin_can_authenticate_and_lands_on_the_operator_dashboard(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'boss@pos.test',
            'password' => 'Password123',
        ]);

        $this->post(route('admin.login.store'), [
            'email' => 'boss@pos.test',
            'password' => 'Password123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_deactivated_admin_cannot_authenticate(): void
    {
        Admin::factory()->create([
            'email' => 'expired@pos.test',
            'password' => 'Password123',
            'is_active' => false,
        ]);

        $this->post(route('admin.login.store'), [
            'email' => 'expired@pos.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_super_admin_can_logout(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_guests_cannot_reach_the_operator_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    // ---------------------------------------------------- guard separation 🔒

    public function test_business_credentials_are_rejected_by_the_admin_login(): void
    {
        // Business users live in a different table entirely — the operator login
        // must not authenticate them under any circumstances.
        $this->post(route('admin.login.store'), [
            'email' => 'owner@acme.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertGuest('web');
    }

    public function test_admin_credentials_are_rejected_by_the_business_login(): void
    {
        Admin::factory()->create([
            'email' => 'boss@pos.test',
            'password' => 'Password123',
        ]);

        $this->post(route('login.store'), [
            'email' => 'boss@pos.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertGuest('admin');
    }

    public function test_business_user_session_cannot_open_the_operator_panel(): void
    {
        $this->actingAs($this->user, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_session_cannot_open_the_business_panel(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_operator_dashboard_is_not_tenant_scoped(): void
    {
        // The admin panel must see every business — the tenant scope has to stay
        // switched off there, or the operator console would render empty.
        Business::factory()->create(['name' => 'Second Business']);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Acme Traders')
            ->assertSee('Second Business');
    }
}
