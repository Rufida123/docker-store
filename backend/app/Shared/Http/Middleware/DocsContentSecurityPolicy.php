<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swagger UI embeds its bootstrap configuration inline. Scope this CSP
 * exception to the documentation UI instead of weakening the default policy
 * for API endpoints.
 */
class DocsContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A handler that chose its own policy (the directory redirect) wins.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'"
            );
        }

        return $response;
    }
}
