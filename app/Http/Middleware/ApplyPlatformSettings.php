<?php

namespace App\Http\Middleware;

use App\Services\PlatformSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the operator's settings in place before anything renders (#110, #111).
 *
 * Runs FIRST in the web stack, ahead of authentication, because the pages that
 * need it most are the ones nobody is signed in for: the login screen reads the
 * brand name, the public site reads whether sign-up is open, and the
 * maintenance page reads the message.
 */
class ApplyPlatformSettings
{
    public function __construct(protected PlatformSettingsService $platform) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->platform->apply();

        return $next($request);
    }
}
