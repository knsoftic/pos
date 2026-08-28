<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureUnavailableException;
use App\Services\FeatureService;
use App\Support\FeatureRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level feature gate (#13, #187 layer 1).
 *
 *   Route::get('/pos', …)->middleware('feature:pos.terminal');
 *
 * Multiple codes are ANDed — `feature:sales.invoicing,reports.export_pdf` needs
 * both. For "either will do", check inside the controller with
 * {@see FeatureService::anyOf()}; a middleware string that could mean AND or OR
 * would be a trap.
 *
 * Hiding a nav link is presentation. THIS is the enforcement — a hand-written
 * POST to the same URL hits this guard too.
 */
class CheckFeature
{
    public function __construct(protected FeatureService $features) {}

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        foreach ($codes as $code) {
            // A typo'd code must fail loudly in development rather than silently
            // granting access to a gate that checks a nonexistent flag.
            if (! FeatureRegistry::exists($code)) {
                throw new \InvalidArgumentException(
                    "Unknown feature code [{$code}] used in a route gate. Add it to ".FeatureRegistry::class.'.'
                );
            }

            if (! $this->features->enabled($code)) {
                throw new FeatureUnavailableException(
                    $code,
                    FeatureRegistry::all()[$code]['name'] ?? null,
                );
            }
        }

        return $next($request);
    }
}
