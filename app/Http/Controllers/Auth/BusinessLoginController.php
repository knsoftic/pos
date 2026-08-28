<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Business-user authentication (web guard → /app panel).
 */
class BusinessLoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        // Validation + rate limiting + is_active gate all live in the request.
        $request->authenticate();

        $request->session()->regenerate();

        // Stamp last login without firing model events (last_login_at is guarded).
        Auth::guard('web')->user()->forceFill([
            'last_login_at' => now(),
        ])->saveQuietly();

        return redirect()->intended(route('app.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
