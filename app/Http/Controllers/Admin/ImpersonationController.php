<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Log in AS a tenant user for support (#6, #178).
 *
 * How it works, and why:
 *
 *  - The two guards are independent (`admin` and `web` use different session
 *    keys), so the operator stays signed in to /admin while the tenant session
 *    runs alongside. Stopping is therefore just a web-guard logout — the
 *    operator never has to re-authenticate, and no admin credential is ever
 *    exchanged for a tenant one.
 *  - The session carries an `impersonation` marker. Nothing about the request
 *    otherwise distinguishes it from a real login, and both the visible banner
 *    and the audit trail depend on knowing the difference.
 *  - `last_login_at` is deliberately NOT stamped: an operator visit is not the
 *    customer logging in, and overwriting it would corrupt real usage data.
 *  - Start and stop are both audited against the tenant, so a customer asking
 *    "who opened my account on Tuesday" has an answer. #177
 */
class ImpersonationController extends Controller
{
    /** Session key holding the marker for an active impersonation. */
    public const SESSION_KEY = 'impersonation';

    public function __construct(protected AuditService $audit) {}

    /** Begin impersonating a user of this business (admin guard). */
    public function start(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $admin = $request->user('admin');

        if ($admin === null) {
            return back()->with('error', 'Your operator session has expired. Sign in again.');
        }

        // Suspended tenants are refused: SetBusinessTenant would immediately tear
        // the session down anyway, and pretending otherwise is just confusing.
        if (! $business->isActive()) {
            return back()->with('error', "\"{$business->name}\" is {$business->status}. Reactivate it before signing in as one of its users.");
        }

        // Always resolved within THIS business — an arbitrary user id must not be
        // reachable from another tenant's page. #117
        $query = User::query()->forBusiness($business->id)->where('is_active', true);

        $user = isset($validated['user_id'])
            ? $query->find($validated['user_id'])
            : $query->orderByDesc('is_business_owner')->first();

        if ($user === null) {
            return back()->with('error', 'No active user found for that selection.');
        }

        Auth::guard('web')->login($user);

        $request->session()->put(self::SESSION_KEY, [
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'business_id' => $business->id,
            'business_name' => $business->name,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'started_at' => now()->toIso8601String(),
        ]);

        $this->audit->log(
            'admin.impersonation_started',
            $user,
            "{$admin->name} started impersonating {$user->email} at {$business->name}.",
            ['admin_email' => $admin->email, 'user_email' => $user->email],
            $admin,
            $business->id,
        );

        return redirect()
            ->route('app.dashboard')
            ->with('success', "You are now signed in as {$user->name}. Use the banner to return to the operator console.");
    }

    /**
     * End the impersonation (web guard).
     *
     * Registered under `auth:web` because it is the tenant session that is being
     * ended — the operator's own admin session is untouched, so this just drops
     * them back into /admin.
     */
    public function stop(Request $request): RedirectResponse
    {
        $marker = $request->session()->get(self::SESSION_KEY);

        Auth::guard('web')->logout();
        $request->session()->forget(self::SESSION_KEY);

        // Not an impersonated session — treat it as a plain logout rather than
        // leaking the existence of the operator console.
        if (! is_array($marker)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $business = Business::find($marker['business_id'] ?? null);

        $this->audit->log(
            'admin.impersonation_stopped',
            $business,
            sprintf(
                '%s stopped impersonating %s at %s.',
                $marker['admin_name'] ?? 'An operator',
                $marker['user_name'] ?? 'a user',
                $marker['business_name'] ?? 'a business',
            ),
            ['started_at' => $marker['started_at'] ?? null],
            null,
            $marker['business_id'] ?? null,
        );

        // The session id is rotated, but NOT invalidated: invalidating would also
        // drop the admin guard's login and force the operator to sign in again.
        $request->session()->regenerate();

        return redirect()
            ->route($business !== null ? 'admin.businesses.show' : 'admin.dashboard', $business !== null ? [$business] : [])
            ->with('success', 'Impersonation ended.');
    }
}
