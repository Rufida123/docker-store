<?php

namespace App\Modules\Users\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits FAILED login attempts: 5 per 15 minutes for the same IP + email.
 * Successful logins are not counted. The counter lives in the shared cache
 * store, so the limit holds across several app processes. Draft-6 RateLimit
 * headers go on every response that passes through here.
 */
class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;

    private const LIMIT = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $email = self::text($request->json('email'));
        $key = 'login-limiter:'.sha1($request->ip().'|'.mb_strtolower($email));
        $resetKey = $key.':reset';

        if (Cache::add($key, 0, self::WINDOW_SECONDS)) {
            Cache::put($resetKey, time() + self::WINDOW_SECONDS, self::WINDOW_SECONDS);
        }

        $hits = Cache::increment($key);
        if ($hits === false) {
            // The window expired between add() and increment(); start a new one.
            Cache::put($key, $hits = 1, self::WINDOW_SECONDS);
            Cache::put($resetKey, time() + self::WINDOW_SECONDS, self::WINDOW_SECONDS);
        }

        $secondsLeft = max(0, (int) (Cache::get($resetKey) ?? time() + self::WINDOW_SECONDS) - time());
        $headers = [
            'RateLimit-Policy' => self::LIMIT.';w='.self::WINDOW_SECONDS,
            'RateLimit-Limit' => (string) self::LIMIT,
            'RateLimit-Remaining' => (string) max(0, self::LIMIT - $hits),
            'RateLimit-Reset' => (string) $secondsLeft,
        ];

        if ($hits > self::LIMIT) {
            $response = ApiResponse::error(
                429,
                'TOO_MANY_REQUESTS',
                'Too many failed login attempts. Try again in 15 minutes.',
            );
            $headers['Retry-After'] = (string) $secondsLeft;
        } else {
            $response = $next($request);

            if ($response->getStatusCode() < 400) {
                Cache::decrement($key);
            }
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The limiter key uses the email field as the client sent it, whatever its
     * JSON type, the way String() would print it. Falsy values count as "".
     */
    private static function text(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === 0.0 || $value === false) {
            return '';
        }

        return match (true) {
            is_string($value) => $value,
            is_bool($value) => 'true',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => array_is_list($value)
                ? implode(',', array_map(self::text(...), $value))
                : '[object Object]',
            default => '[object Object]',
        };
    }
}
