<?php

namespace App\Shared\Http\Middleware;

use App\Shared\AppError;
use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authentication: who are you? Checks the JWT and stores the caller on the
 * request. HS256 only; anything else in the token header is an invalid token.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $parts = explode(' ', (string) $request->headers->get('Authorization', ''));
        $scheme = $parts[0];
        $token = $parts[1] ?? '';

        if ($scheme !== 'Bearer' || $token === '') {
            throw new AppError(401, 'UNAUTHORIZED', 'Missing or malformed Authorization header');
        }

        try {
            $payload = JWT::decode($token, new Key((string) config('store.jwt.secret'), 'HS256'));
        } catch (ExpiredException) {
            throw new AppError(401, 'UNAUTHORIZED', 'Token expired');
        } catch (Throwable) {
            throw new AppError(401, 'UNAUTHORIZED', 'Invalid token');
        }

        $request->attributes->set('user', [
            'id' => $payload->sub ?? null,
            'role' => $payload->role ?? null,
        ]);

        return $next($request);
    }
}
