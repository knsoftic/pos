<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\SetBusinessTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => SetBusinessTenant::class,
            'subscription' => CheckSubscription::class,
            'feature' => CheckFeature::class,
            'permission' => CheckPermission::class,
        ]);

        // The standard stack for every business-facing route. Bundled as a group
        // so the subscription gate cannot be forgotten when a route is added —
        // leaving it off a single route would be a silent paywall hole. #187
        // Order matters: authenticate, resolve the tenant, then check its plan.
        $middleware->group('tenant.app', [
            'auth:web',
            'tenant',
            'subscription',
        ]);

        /*
         | ⚠️ THE TENANT MUST BE RESOLVED BEFORE ROUTE MODEL BINDING.
         |
         | SubstituteBindings lives in the `web` group, which runs BEFORE a
         | route's own middleware. Without this, `/app/roles/{role}` would look
         | the row up with no tenant context — i.e. unscoped — and happily hand
         | one business another business's record before SetBusinessTenant ever
         | ran. Declaring the order here fixes it for every bound route at once,
         | rather than every controller having to re-check ownership. (#3, #117)
         */
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetBusinessTenant::class,
        );

        // Guard-aware auth redirects. The two panels (/admin super-admin vs
        // business app) have independent login screens, so route requests to
        // the correct one based on the URL prefix.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : route('login')
        );

        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.dashboard') : route('app.dashboard')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
