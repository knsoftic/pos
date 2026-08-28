<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Password reset flow. #63
 *
 * Beyond "does it work", this asserts the two things that are easy to get wrong
 * and expensive to get wrong:
 *   - the endpoint must not leak WHETHER an email is registered (enumeration),
 *   - a deactivated account must not be able to reset its way back in.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->for($this->business)->create([
            'email' => 'reset@acme.test',
            'password' => 'OldPassword123',
        ]);
    }

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_reset_link_is_emailed_to_a_registered_user(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'reset@acme.test'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->user, ResetPassword::class);
    }

    public function test_response_is_identical_for_unknown_emails(): void
    {
        Notification::fake();

        // Same status message, no validation error — an attacker cannot tell
        // registered addresses from unregistered ones.
        $this->post(route('password.email'), ['email' => 'nobody@nowhere.test'])
            ->assertSessionHas('status', trans('passwords.sent'))
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_deactivated_user_is_not_sent_a_reset_link(): void
    {
        Notification::fake();

        $this->user->forceFill(['is_active' => false])->save();

        $this->post(route('password.email'), ['email' => 'reset@acme.test'])
            ->assertSessionHas('status', trans('passwords.sent'))
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_repeat_requests_for_the_same_account_are_throttled(): void
    {
        Notification::fake();

        // The framework's own broker throttle (config/auth.php → passwords.users.throttle)
        // blocks a second link for the same address inside the throttle window.
        $this->post(route('password.email'), ['email' => 'reset@acme.test'])
            ->assertSessionHasNoErrors();

        $this->post(route('password.email'), ['email' => 'reset@acme.test'])
            ->assertSessionHasErrors('email');

        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
    }

    public function test_reset_requests_from_one_ip_are_rate_limited_across_addresses(): void
    {
        Notification::fake();

        // Unknown addresses are not throttled by the broker (there is no user to
        // throttle), so without a per-IP limit an attacker could sweep thousands
        // of emails. Lowered here so the test stays fast.
        config(['security.throttle.password_reset_ip_max_attempts' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => "sweep{$i}@nowhere.test"])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'sweep99@nowhere.test'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_reset_screen_can_be_rendered_with_a_token(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'reset@acme.test']);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            $this->get(route('password.reset', $notification->token))->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'reset@acme.test']);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            $response = $this->post(route('password.store'), [
                'token' => $notification->token,
                'email' => 'reset@acme.test',
                'password' => 'BrandNew123',
                'password_confirmation' => 'BrandNew123',
            ]);

            $response->assertRedirect(route('login'))->assertSessionHasNoErrors();

            $this->assertTrue(Hash::check('BrandNew123', $this->user->fresh()->password));

            return true;
        });
    }

    public function test_new_password_must_satisfy_the_configured_policy(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'reset@acme.test']);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            // Too short, no uppercase, no digits — fails the Password::defaults()
            // rule built from config('security.password').
            $this->post(route('password.store'), [
                'token' => $notification->token,
                'email' => 'reset@acme.test',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])->assertSessionHasErrors('password');

            $this->assertTrue(Hash::check('OldPassword123', $this->user->fresh()->password));

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_an_invalid_token(): void
    {
        $this->post(route('password.store'), [
            'token' => 'totally-made-up-token',
            'email' => 'reset@acme.test',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('OldPassword123', $this->user->fresh()->password));
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'reset@acme.test']);

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            $payload = [
                'token' => $notification->token,
                'email' => 'reset@acme.test',
                'password' => 'BrandNew123',
                'password_confirmation' => 'BrandNew123',
            ];

            $this->post(route('password.store'), $payload)->assertSessionHasNoErrors();

            // Second attempt with the same token must fail — tokens are single use.
            $this->post(route('password.store'), $payload)->assertSessionHasErrors('email');

            return true;
        });
    }
}
