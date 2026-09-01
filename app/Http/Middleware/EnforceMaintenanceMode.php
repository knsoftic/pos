<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode for the SHOPS, not for the operator (#160).
 *
 * ================= WHY NOT `php artisan down` =================
 * Laravel's maintenance mode takes the whole application off the air, /admin
 * included — which locks the operator out of the one screen that would turn it
 * back on. It is also a file on one server, so a platform behind two web
 * servers goes half-down. This is a database flag, and it deliberately leaves
 * the admin panel reachable.
 *
 * ================= WHAT STAYS OPEN =================
 *   /admin       — always. Otherwise the switch is unreachable.
 *   a signed-in super admin — anywhere, so they can check a shop's workspace
 *                  actually works before letting everyone back in.
 *   ?maintenance_token=… — the same, for whoever is running the deploy and is
 *                  not signed in yet. Remembered in the session so the next
 *                  click does not need the query string again.
 *   /up          — the health check. A load balancer taking the box out of
 *                  rotation during a planned window is exactly wrong.
 *   logging out  — never trap somebody in a session they cannot end.
 *
 * ⚠️ 503 with a Retry-After, not 200. A maintenance page that answers 200 gets
 * cached and indexed as the site's content, and search engines keep serving it
 * long after the work is done.
 */
class EnforceMaintenanceMode
{
    protected const SESSION_KEY = 'maintenance_bypass';

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('platform.maintenance', false)) {
            return $next($request);
        }

        if ($this->mayPass($request)) {
            return $next($request);
        }

        return response()
            ->view('maintenance', [
                'message' => (string) config('platform.maintenance_message', ''),
            ], 503)
            ->header('Retry-After', '600');
    }

    protected function mayPass(Request $request): bool
    {
        // The operator's own panel, and the health check.
        if ($request->is('admin', 'admin/*', 'up')) {
            return true;
        }

        // Never trap somebody in a session they cannot end.
        if ($request->is('logout') || $request->routeIs('logout')) {
            return true;
        }

        if (Auth::guard('admin')->check()) {
            return true;
        }

        $token = (string) config('platform.maintenance_token', '');

        if ($token !== '' && hash_equals($token, (string) $request->query('maintenance_token', ''))) {
            $request->session()->put(self::SESSION_KEY, $token);

            return true;
        }

        // A token accepted once holds for the rest of the visit — otherwise
        // every link during a check would need the query string reattached.
        return $token !== '' && hash_equals($token, (string) $request->session()->get(self::SESSION_KEY, ''));
    }
}
