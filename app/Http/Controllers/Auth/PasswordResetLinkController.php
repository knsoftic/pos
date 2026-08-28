<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Step 1 of password reset (#63): email the reset link.
 *
 * SECURITY: the response is deliberately identical whether or not the email
 * exists, so this endpoint cannot be used to enumerate accounts. Deactivated
 * users are excluded from the lookup, so they cannot regain access this way.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $decay = (int) config('security.throttle.password_reset_decay_minutes') * 60;

        foreach ([$this->throttleKey($request), $this->ipThrottleKey($request)] as $key) {
            RateLimiter::hit($key, $decay);
        }

        $status = Password::broker('users')->sendResetLink([
            'email' => $request->string('email')->toString(),
            'is_active' => true,
        ]);

        // Surface only the throttle case; everything else returns the same
        // generic confirmation to avoid leaking whether the account exists.
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => trans('passwords.throttled'),
            ]);
        }

        return back()->with('status', trans('passwords.sent'));
    }

    /**
     * Two throttle layers (#65):
     *   - per email + IP: nobody can hammer a single account's reset endpoint.
     *   - per IP alone: nobody can sweep hundreds of addresses looking for hits,
     *     which is the enumeration attack the generic response also defends against.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        $limits = [
            $this->throttleKey($request) => (int) config('security.throttle.password_reset_max_attempts'),
            $this->ipThrottleKey($request) => (int) config('security.throttle.password_reset_ip_max_attempts'),
        ];

        foreach ($limits as $key => $max) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip().'|pw-reset');
    }

    protected function ipThrottleKey(Request $request): string
    {
        return $request->ip().'|pw-reset-ip';
    }
}
