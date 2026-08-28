<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Business-user login request (web guard).
 *
 * Bundles validation, brute-force rate limiting (#65) and the post-auth
 * "is_active" account gate (#63) so the controller stays thin.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), $this->decaySeconds());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Credentials are valid — but a deactivated user must not keep a session.
        if (! Auth::guard('web')->user()->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact your administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts())) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /** Throttle limits are config-driven, never hardcoded. #190 */
    protected function maxAttempts(): int
    {
        return (int) config('security.throttle.login_max_attempts');
    }

    protected function decaySeconds(): int
    {
        return (int) config('security.throttle.login_decay_minutes') * 60;
    }

    /**
     * Rate-limit key: per email + IP. Distinct from the admin guard.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip().'|web');
    }
}
