<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP revalidation for public GET resources (docs/performance.md, 6.5).
 *
 * A successful GET/HEAD gets a strong ETag computed from the final body and
 * "Cache-Control: no-cache": a client may keep its copy but must ask every
 * time, so an edit is visible on the next request. When If-None-Match
 * matches, the answer is 304 with no body.
 *
 * The ETag is a hash of the bytes sent, so it changes whenever any field of
 * the response changes (price, stock, stock_status, page, ...), without a
 * list of inputs to keep in sync. The server still builds the response; a 304
 * saves the transfer and the client's work, not the server's.
 *
 * Only for responses that are the same for every caller: the routes using it
 * must not depend on the user or role (otherwise add Vary: Authorization).
 */
class ETagRevalidation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodCacheable()) {
            return $next($request); // writes: no validators
        }

        // Laravel empties the body of a HEAD response before route middleware
        // sees it, which would give HEAD the ETag of an empty string. Build
        // the response as a GET, then drop the body ourselves.
        $isHead = $request->isMethod('HEAD');
        if ($isHead) {
            $request->setMethod('GET');
        }
        try {
            $response = $next($request);
        } finally {
            if ($isHead) {
                $request->setMethod('HEAD');
            }
        }

        // Errors and redirects are passed through untouched: a 304 never
        // replaces an error, and only a 200 body has a stable identity.
        $content = $response->getContent();
        if ($response->getStatusCode() !== 200 || $content === false) {
            return $isHead ? self::withoutBody($response) : $response;
        }

        $etag = '"'.hash('xxh128', $content).'"';
        $response->headers->set('ETag', $etag);
        // no-cache: keep a copy but revalidate every time (never max-age).
        // public: the body is the same for every caller. It must be explicit:
        // Symfony appends "private" to a Cache-Control without public/private.
        $response->headers->set('Cache-Control', 'no-cache, public');

        if (self::matches($request->headers->get('If-None-Match'), $etag)) {
            $response->setStatusCode(304);
            $response->setContent('');
            // Same validators and caching rules as the 200, no entity headers.
            $response->headers->remove('Content-Type');
            $response->headers->remove('Content-Length');

            return $response;
        }

        return $isHead ? self::withoutBody($response) : $response;
    }

    /** HEAD: the GET's headers (Content-Length included), no body. */
    private static function withoutBody(Response $response): Response
    {
        $content = $response->getContent();
        if ($content !== false) {
            $response->headers->set('Content-Length', (string) strlen($content));
            $response->setContent('');
        }

        return $response;
    }

    /**
     * RFC 9110 13.1.2: If-None-Match is "*" or a comma-separated list of
     * entity tags, compared with the WEAK comparison (W/"x" matches "x").
     */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }
        if (trim($ifNoneMatch) === '*') {
            return true; // the resource exists (we only get here on a 200)
        }

        $current = self::opaque($etag);
        // Entity tags can't contain '"' inside, so commas between quotes are
        // the only separators that matter; a tag never contains a comma
        // outside its quotes.
        if (! preg_match_all('/(?:W\/)?"[^"]*"/', $ifNoneMatch, $tags)) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (self::opaque($tag) === $current) {
                return true;
            }
        }

        return false;
    }

    private static function opaque(string $tag): string
    {
        $tag = trim($tag);

        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
