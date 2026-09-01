<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant (business) from the AUTHENTICATED user and
 * binds it into the request-scoped TenantContext. Runs after `auth:web`.
 *
 * SECURITY — the heart of tenant isolation:
 *  - The tenant is derived ONLY from the logged-in user. It is never read from
 *    the URL, a header, a query string, or the request body. A user therefore
 *    cannot spoof or switch their business_id. #4 / #182
 *  - Fails closed: any missing / inactive / suspended state ends the session
 *    or aborts rather than silently falling back to an unscoped context.
 */
class SetBusinessTenant
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branch,
        protected SettingsService $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        // `auth:web` should guarantee this, but never assume — fail closed.
        abort_if($user === null, 403, 'Authentication required.');

        // Deactivated staff: kill the session even if a live one exists.
        if (! $user->is_active) {
            return $this->logoutWithError($request, 'Your account has been deactivated.');
        }

        // Tenant is ALWAYS resolved from the authenticated user (belongsTo).
        // Business has SoftDeletes, so a trashed business resolves to null here.
        $business = $user->business()->first();

        if ($business === null) {
            return $this->logoutWithError($request, 'Your account is not linked to an active business.');
        }

        // Business-level gate: only ACTIVE businesses may use the app. Suspended
        // / inactive businesses are handled by the subscription layer in Phase 2;
        // for now they are simply denied access.
        if (! $business->isActive()) {
            return $this->logoutWithError(
                $request,
                'This business account is currently '.$business->status.'. Please contact support.'
            );
        }

        $this->tenant->setBusiness($business);

        /*
        | The shop's own settings are overlaid onto the config repository right
        | here (#57). From this line on, `config('pos.cash_rounding')` and every
        | other knob return THIS business's answer, so the sale engine, the
        | till, the receipt and the reports all follow the shop without a single
        | one of them knowing a settings table exists.
        |
        | It has to happen after the tenant is set and before anything reads a
        | knob, which is why it lives in the middleware and not in a provider.
        */
        $this->settings->apply($business);

        // Which branches this person may reach (#48, #138). Same rule as the
        // tenant: derived from the authenticated user, never from the request,
        // and it can only narrow what the tenant scope already allows.
        $this->branch->forUser($user);

        // Make the tenant available to every view without per-controller wiring.
        view()->share('currentBusiness', $business);

        return $next($request);
    }

    /**
     * Terminate the session and bounce back to login with a clear reason.
     */
    protected function logoutWithError(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
