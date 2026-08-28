<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\SetBusinessTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
