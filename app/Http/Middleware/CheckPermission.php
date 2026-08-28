<?php

namespace App\Http\Middleware;

use App\Exceptions\PermissionDeniedException;
use App\Services\PermissionService;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate (#130, #188).
 *
 *   Route::get('employees', …)->middleware('permission:employees.view');
 *
 * Multiple codes are ANDed, matching {@see CheckFeature}. For "either will do",
 * ask {@see PermissionService::anyOf()} in the controller — a middleware string
 * that could mean AND or OR would be a trap.
 *
 * Because {@see PermissionService} runs all three layers, this one middleware
 * also enforces the plan feature behind the permission: no route needs to
 * declare both `feature:` and `permission:` for the same capability.
 */
class CheckPermission
{
    public function __construct(protected PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        foreach ($codes as $code) {
            // A typo must fail loudly in development rather than silently
            // guarding nothing.
            if (! PermissionRegistry::exists($code)) {
                throw new \InvalidArgumentException(
                    "Unknown permission code [{$code}] used in a route gate. Add it to ".PermissionRegistry::class.'.'
                );
            }

            if (! $this->permissions->allows($code)) {
                throw new PermissionDeniedException($code);
            }
        }

        return $next($request);
    }
}
