<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Listeners\LogRolledBackTransaction;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\PermissionService;
use App\Services\PlanLimitService;
use App\Services\PlatformSettingsService;
use App\Services\SecurityLogger;
use App\Services\SettingsService;
use App\Support\BranchContext;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(PlatformSettingsService::class);

        /*
         | Scoped for a different reason: the logger mints ONE reference per
         | request, and its whole purpose is that the code on the user's error
         | page and the code in the log file are the same code. A fresh instance
         | per injection would give them two.
         */
        $this->app->scoped(SecurityLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        | Take a copy of what the config FILES say, before any request overlays
        | a shop's own settings onto them (#57). Without this snapshot, "back to
        | default" would have nothing to compare against — see SettingsService.
        */
        SettingsService::snapshotDefaults();
        PlatformSettingsService::snapshotDefaults();

        $this->configurePasswordPolicy();
        $this->registerPermissionGates();
        $this->configureRateLimiters();

        // Authentication events (login/logout/failed/lockout/reset) → audit trail.
        Event::subscribe(AuthEventSubscriber::class);

        /*
        | A discarded transaction is the only failure that leaves no evidence in
        | the database, so it has to leave some in a log (#94, #98). Listening
        | for the rollback rather than editing fifty `DB::transaction` call
        | sites means a service written next year is covered without knowing it.
        */
        Event::listen(TransactionRolledBack::class, LogRolledBackTransaction::class);
    }

    /**
     * Named limiters for the endpoints that are expensive, unauthenticated, or
     * both (#65, #100). Declared here rather than inline on the routes so the
     * numbers stay in config/security.php and a limit can be tuned in
     * production without a deploy.
     */
    protected function configureRateLimiters(): void
    {
        /*
        | Note the config is read INSIDE the closure, per request, not captured
        | here at boot. A limit read once at boot is a limit that cannot be
        | changed without a restart — and, less obviously, one no test can turn
        | down, which means nobody ever proves it fires.
        */
        $throttle = fn (string $key, string $setting) => RateLimiter::for(
            $key,
            fn (Request $request) => Limit::perMinute((int) config($setting))
                ->by($request->user()?->id ?: $request->ip()),
        );

        /*
        | Sign-up is the one that matters most: an unauthenticated endpoint that
        | creates a business, a user and a subscription. Left open, a script
        | fills the tenant list with junk that then has to be told apart from
        | real shops by hand. Per HOUR, not per minute — nobody opens six shops
        | in an hour, and a window that resets in sixty seconds stops nothing.
        */
        RateLimiter::for('register', fn (Request $request) => Limit::perMinutes(
            (int) config('security.throttle.register_decay_minutes'),
            (int) config('security.throttle.register_max_attempts'),
        )->by($request->ip()));

        $throttle('search', 'security.throttle.search_per_minute');
        $throttle('export', 'security.throttle.export_per_minute');
        $throttle('sale', 'security.throttle.sale_per_minute');
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
