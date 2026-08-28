<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordPolicy();

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
}
