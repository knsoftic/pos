<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Super-admin (SaaS operator) authentication (admin guard → /admin panel).
 *
 * A completely separate guard from business users: an admin session and a
 * business session are independent, and neither can act as the other. Super
 * admins are NOT tenant-scoped. #2 / #182
 */
class AdminLoginController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit(
                $this->throttleKey($request),
                (int) config('security.throttle.login_decay_minutes') * 60
            );

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (! Auth::guard('admin')->user()->is_active) {
            Auth::guard('admin')->logout();

            throw ValidationException::withMessages([
                'email' => 'This admin account has been deactivated.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        Auth::guard('admin')->user()->forceFill([
            'last_login_at' => now(),
        ])->saveQuietly();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        // Config-driven, never hardcoded. #190
        $maxAttempts = (int) config('security.throttle.login_max_attempts');

        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), $maxAttempts)) {
            return;
        }

        // Fired so the lockout lands in the audit log, same as the web guard.
        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip().'|admin');
    }
}
