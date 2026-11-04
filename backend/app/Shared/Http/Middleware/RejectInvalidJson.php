<?php

namespace App\Shared\Http\Middleware;

use App\Shared\AppError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates JSON request bodies before routing, strict mode: the body must
 * parse and the top level must be an object or an array. Other content types
 * pass through untouched, and an empty body counts as "no body", not an error.
 */
class RejectInvalidJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mime === 'application/json') {
            $body = $request->getContent();

            if ($body !== '') {
                $trimmed = ltrim($body);
                $first = $trimmed === '' ? '' : $trimmed[0];

                if (! in_array($first, ['{', '['], true) || ! json_validate($body)) {
                    throw new AppError(400, 'INVALID_JSON', 'Request body is not valid JSON');
                }
            }
        }

        return $next($request);
    }
}
