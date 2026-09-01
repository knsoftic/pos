<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\PermissionService;
use App\Services\PlanLimitService;
use App\Support\BranchContext;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request — the single source of truth for tenancy.
        $this->app->singleton(TenantContext::class);

        // Which branches the current user may reach (#48). Same lifetime and the
        // same rule: resolved from the authenticated user, never from input.
        $this->app->singleton(BranchContext::class);

        /*
         | The entitlement services memoise their resolved maps for the life of
         | the request, and each exposes a flush() for when a plan or an override
         | changes (#10, #96). Those two facts only work together if EVERYONE
         | shares one instance: with a fresh instance per injection, an override
         | screen could flush its own copy while the layout rendering the same
         | page kept a stale one, and answer the same entitlement question two
         | different ways.
         |
         | `scoped`, not `singleton`: one instance per request or queued job, so a
         | long-lived worker never carries one tenant's entitlements into the
         | next tenant's job.
         */
        $this->app->scoped(FeatureService::class);
        $this->app->scoped(PlanLimitService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordPolicy();
        $this->registerPermissionGates();

        // Authentication events (login/logout/failed/lockout/reset) → audit trail.
        Event::subscribe(AuthEventSubscriber::class);
    }

    /**
     * One password policy for the whole app, driven by config/security.php so
     * nothing is hardcoded. Used anywhere via `Password::defaults()`. #63 / #190
     */
    protected function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            $policy = config('security.password');

            $rule = Password::min($policy['min_length']);

            if ($policy['require_mixed_case']) {
                $rule->mixedCase();
            }

            if ($policy['require_numbers']) {
                $rule->numbers();
            }

            if ($policy['require_symbols']) {
                $rule->symbols();
            }

            if ($policy['require_uncompromised']) {
                $rule->uncompromised();
            }

            return $rule;
        });
    }

    /**
     * Every permission code becomes a Gate ability, so Blade can ask
     * `@can('products.create')` and controllers `$this->authorize(...)` — while
     * the actual decision stays in one place, {@see PermissionService}, which
     * runs all three layers (#187, #188).
     *
     * Note the deliberate absence of a `Gate::before` owner bypass: the owner
     * shortcut lives inside the service, so a check made through the service and
     * a check made through the Gate can never disagree.
     */
    protected function registerPermissionGates(): void
    {
        foreach (PermissionRegistry::codes() as $code) {
            Gate::define($code, function ($user) use ($code) {
                // Super admins authenticate on another guard and are not part of
                // any tenant's role system.
                if (! $user instanceof User) {
                    return false;
                }

                return app(PermissionService::class)->allows($code, $user);
            });
        }
    }
}
