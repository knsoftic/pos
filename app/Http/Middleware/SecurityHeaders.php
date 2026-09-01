<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defences, on every response (#100).
 *
 * These are all instructions to the browser, which means they are worth exactly
 * as much as their coverage: a header on nine routes out of ten protects
 * nothing, because an attacker picks the tenth. So it runs on the whole `web`
 * group rather than being opted into per route.
 *
 * ⚠️ There is deliberately NO Content-Security-Policy — see config/security.php
 * for why a CSP this app could pass today would have to allow the exact thing a
 * CSP exists to forbid.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = (array) config('security.headers', []);

        $this->set($response, 'X-Content-Type-Options', $headers['content_type_options'] ?? null);
        $this->set($response, 'X-Frame-Options', $headers['frame_options'] ?? null);
        $this->set($response, 'Referrer-Policy', $headers['referrer_policy'] ?? null);
        $this->set($response, 'Permissions-Policy', $headers['permissions_policy'] ?? null);

        /*
         | HSTS is the one header here that cannot be taken back: once a browser
         | has seen it, that hostname is HTTPS-only for max-age seconds whatever
         | the server later says. Sent over plain HTTP it is ignored anyway, so
         | the check is not caution — it is the only correct behaviour.
         */
        if (($headers['hsts_enabled'] ?? false) && $request->secure()) {
            $value = 'max-age='.(int) ($headers['hsts_max_age'] ?? 31536000);

            if ($headers['hsts_include_subdomains'] ?? false) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        /*
         | Nothing under /app or /admin should ever sit in a shared cache. A
         | proxy holding one cashier's sales list and handing it to the next
         | request is a tenant leak that never touches our code.
         */
        if ($this->isPrivate($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    protected function set(Response $response, string $header, ?string $value): void
    {
        // An empty string in config is a deliberate "do not send this one".
        if ($value === null || $value === '') {
            return;
        }

        $response->headers->set($header, $value);
    }

    protected function isPrivate(Request $request): bool
    {
        return $request->is('app', 'app/*', 'admin', 'admin/*');
    }
}
