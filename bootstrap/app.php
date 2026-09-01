<?php

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\PermissionDeniedException;
use App\Http\Middleware\ApplyPlatformSettings;
use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetBusinessTenant;
use App\Services\SecurityLogger;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        /*
         | Browser-side defences on EVERY response (#100).
         |
         | ⚠️ GLOBAL, not on the `web` group, and that distinction is the whole
         | point. Group middleware is attached to ROUTES, so a request that
         | matches no route — every 404, including the ones an attacker probes
         | with — skips it entirely and comes back with no headers at all. The
         | global stack wraps the router itself, so the response that had no
         | route still gets the headers. A test asserts them on a 404 for
         | exactly this reason.
         */
        $middleware->append(SecurityHeaders::class);

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
        /*
         |----------------------------------------------------------------------
         | 1. Nothing sensitive survives a failed request  (#100)
         |----------------------------------------------------------------------
         | Laravel flashes old input back into the session so a form can be
         | refilled. Anything named here is left out — a password flashed into
         | the session store is a password written to disk, and the request that
         | put it there was the one that went wrong.
         */
        $exceptions->dontFlash((array) config('security.logging.redact', []));

        /*
         |----------------------------------------------------------------------
         | 2. Every reported exception says WHO and WHERE  (#94)
         |----------------------------------------------------------------------
         | A stack trace answers "what broke". On a multi-tenant platform the
         | first question is actually "whose shop, and can they still trade?" —
         | which a trace never answers on its own.
         |
         | The `ref` is the same code the 500 page shows the user, so a phone
         | call quoting six characters lands on this exact entry.
         */
        $exceptions->context(function (): array {
            $logger = app(SecurityLogger::class);
            $tenant = app(TenantContext::class);

            return array_filter([
                'ref' => $logger->reference(),
                'business_id' => $tenant->hasBusiness() ? $tenant->businessId() : null,
                'user_id' => auth('web')->id(),
                'admin_id' => auth('admin')->id(),
            ], fn ($value) => $value !== null);
        });

        /*
         |----------------------------------------------------------------------
         | 3. A refusal is not a crash  (#100)
         |----------------------------------------------------------------------
         | These four exceptions ARE the system working. Reporting them as
         | application errors buries the real ones: an error log where most
         | entries are "cashier lacks a permission" is an error log nobody reads.
         |
         | They are not silenced, though. A permission denial and a lockout go to
         | the security channel as refusals — the value of a defence is knowing
         | how often it fires, and a denial that reaches the exception layer at
         | all means somebody posted to a URL they had no button for.
         */
        $exceptions->report(function (PermissionDeniedException $e): bool {
            app(SecurityLogger::class)->refused('permission.denied', $e->getMessage(), $e->context());

            return false;
        });

        $exceptions->report(function (ThrottleRequestsException $e): bool {
            app(SecurityLogger::class)->refused('throttle.exceeded', 'Rate limit hit.', [
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
            ]);

            return false;
        });

        // A paywall and a business rule. Both are correct answers, neither is
        // a security event, and neither belongs in an error log.
        $exceptions->dontReport([
            FeatureUnavailableException::class,
            LimitExceededException::class,
            InsufficientStockException::class,
        ]);

        /*
         |----------------------------------------------------------------------
         | 4. Anything genuinely unexpected also lands in the security log  (#93)
         |----------------------------------------------------------------------
         | Not instead of the application log — as well as it. The security file
         | is what an operator reads end to end after an incident, and an
         | unexplained 500 during a suspicious hour is part of that story.
         |
         | Returning nothing (not false) lets normal reporting continue.
         */
        $exceptions->report(function (Throwable $e): void {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return;
            }

            app(SecurityLogger::class)->exception($e);
        });
    })->create();
