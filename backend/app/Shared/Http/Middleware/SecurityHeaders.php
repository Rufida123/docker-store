<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The helmet@7 default header set, applied to every response including error
 * responses and static files. Routes that need a different value for one of
 * these headers (the docs UI's CSP, the image CORP) set it themselves; this
 * middleware only fills in what is missing.
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self';base-uri 'self';font-src 'self' https: data:;"
        ."form-action 'self';frame-ancestors 'self';img-src 'self' data:;object-src 'none';"
        ."script-src 'self';script-src-attr 'none';style-src 'self' https: 'unsafe-inline';"
        .'upgrade-insecure-requests';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }

        if (! $headers->has('Cross-Origin-Resource-Policy')) {
            $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        }

        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Origin-Agent-Cluster', '?1');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-DNS-Prefetch-Control', 'off');
        $headers->set('X-Download-Options', 'noopen');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('X-XSS-Protection', '0');
        $headers->remove('X-Powered-By');

        // Symfony adds "no-cache, private" when no cache policy was chosen.
        // This API only sends cache headers where a route sets them on purpose.
        if ($headers->get('Cache-Control') === 'no-cache, private') {
            $headers->remove('Cache-Control');
        }

        return $response;
    }
}
