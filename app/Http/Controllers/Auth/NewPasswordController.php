<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Step 2 of password reset (#63): validate the token and set a new password.
 *
 * The new password must satisfy the app-wide policy from config/security.php
 * (via PasswordRule::defaults()), so strength rules live in one place.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            [
                ...$request->only('email', 'password', 'password_confirmation', 'token'),
                'is_active' => true,
            ],
            function (User $user, string $password) {
                // `password` is cast as hashed, so assigning plaintext hashes it.
                // Rotating remember_token invalidates any "remember me" cookies.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => trans($status),
            ]);
        }

        return redirect()->route('login')->with('status', trans($status));
    }
}
