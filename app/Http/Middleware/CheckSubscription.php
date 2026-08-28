<?php

namespace App\Http\Middleware;

use App\Enums\ExpiryBehavior;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every tenant request on a live subscription (#79, #127, #187 layer 1).
 *
 * Runs AFTER `tenant`, so {@see \App\Support\TenantContext} is already resolved
 * from the authenticated user — this middleware never reads a business from the
 * request.
 *
 * WHAT HAPPENS WHEN A SUBSCRIPTION LAPSES is operator policy, not a hardcoded
 * decision (#190) — `config('subscription.expiry_behavior')`:
 *   lock      — nothing but billing is reachable
 *   read_only — data stays visible, writes are refused (the default: a customer
 *               who forgot to pay should never be locked out of their own
 *               records, only stopped from adding more)
 *   pos_off   — only the selling screen stops; back-office keeps working
 *
 * ALWAYS-ALLOWED ROUTES: billing and logout. Blocking those would trap the
 * tenant in a redirect loop with no way to pay or leave.
 */
class CheckSubscription
{
    /** Route-name prefixes that stay reachable no matter what. */
    protected array $alwaysAllowed = [
        'app.billing',
        'logout',
    ];

    public function __construct(protected SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        $subscription = $this->subscriptions->current();

        // No subscription at all: nothing is entitled. Send them to billing.
        if ($subscription === null) {
            return $this->deny(
                $request,
                'Your account has no active subscription. Please choose a plan to continue.',
                'no_subscription',
            );
        }

        // Trial or paid (grace counts as paid) — carry on.
        if ($subscription->grantsAccess()) {
            return $next($request);
        }

        return match (ExpiryBehavior::fromConfig()) {
            ExpiryBehavior::ReadOnly => $this->handleReadOnly($request, $next),
            ExpiryBehavior::PosOff => $this->handlePosOff($request, $next),
            ExpiryBehavior::Lock => $this->deny(
                $request,
                'Your subscription has expired. Renew it to regain access.',
                'subscription_expired',
            ),
        };
    }

    /**
     * Reads pass, writes do not. Checked on the HTTP method rather than a route
     * allow-list so a route added in a later phase is covered by default.
     */
    protected function handleReadOnly(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            $request->attributes->set('subscription_read_only', true);
            view()->share('subscriptionReadOnly', true);

            return $next($request);
        }

        return $this->deny(
            $request,
            'Your subscription has expired. Your data is still here, but you cannot make changes until you renew.',
            'subscription_read_only',
        );
    }

    /** Only the POS terminal closes; the rest of the app stays usable. */
    protected function handlePosOff(Request $request, Closure $next): Response
    {
        $routeName = (string) $request->route()?->getName();

        if (str_starts_with($routeName, 'app.pos')) {
            return $this->deny(
                $request,
                'Your subscription has expired, so the POS terminal is unavailable. Renew to start selling again.',
                'subscription_expired',
            );
        }

        view()->share('subscriptionExpired', true);

        return $next($request);
    }

    protected function isAlwaysAllowed(Request $request): bool
    {
        $routeName = (string) $request->route()?->getName();

        foreach ($this->alwaysAllowed as $prefix) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    protected function deny(Request $request, string $message, string $reason): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'error' => $reason,
            ], 403);
        }

        // Writes bounce back to the form so nothing typed is lost; reads go to
        // the billing page where the problem can actually be fixed.
        if (! $request->isMethodSafe()) {
            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('app.billing.index')->with('error', $message);
    }
}
