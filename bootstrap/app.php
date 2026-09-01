<?php

use App\Http\Middleware\ApplyPlatformSettings;
use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\SetBusinessTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
        /*
         | The operator's settings go on FIRST, ahead of authentication, because
         | the pages that need them most are the ones nobody is signed in for:
         | the login screen reads the brand name and the maintenance page reads
         | the operator's message (#110, #111).
         |
         | Maintenance comes straight after — before auth, so a closed platform
         | does not first ask a shopkeeper to sign in and only then tell them it
         | is shut (#160).
         */
        $middleware->appendToGroup('web', ApplyPlatformSettings::class);
        $middleware->appendToGroup('web', EnforceMaintenanceMode::class);

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

        /*
         | ⚠️ MAINTENANCE HAS TO BEAT AUTHENTICATION, AND FOLLOW THE SESSION.
         |
         | Laravel sorts the stack by its priority list, and anything NOT in
         | that list can be pushed behind everything that is. Left alone, a
         | closed platform asked a shopkeeper to sign in and only then told them
         | it was shut — and the maintenance check ran before StartSession, so
         | the preview token could not be remembered.
         |
         | Anchoring both here fixes the order once for every route:
         |   … StartSession → ApplyPlatformSettings → EnforceMaintenanceMode
         |     → authentication → … (#110, #160)
         |
         | The anchor is the CONTRACT, `AuthenticatesRequests`, because that is
         | what Laravel's own list names — the concrete `Authenticate` is not in
         | it, so anchoring to that would silently do nothing.
         */
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnforceMaintenanceMode::class,
        );

        $middleware->prependToPriorityList(
            EnforceMaintenanceMode::class,
            ApplyPlatformSettings::class,
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
