<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Database\ReadRouter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Read-your-writes across requests (docs/performance.md, 6.6): after a
 * successful write by a signed-in user, store the primary's WAL position for
 * that user, so ReadRouter keeps their reads on the primary until the replica
 * has replayed it.
 *
 * Done in handle(), after the controller and BEFORE the response is returned,
 * never in terminate(): the client receives the response only after the key
 * exists, so even its very next GET is routed correctly. The write itself is
 * committed by then (the controller returned).
 */
class RecordWrites
{
    public function __construct(private ReadRouter $router)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->attributes->get('user');
        if ($this->router->enabled()
            && ! $request->isMethodSafe()
            && $response->isSuccessful()
            && is_array($user) && is_string($user['id'] ?? null)) {
            try {
                $this->router->recordUserWrite($user['id']);
            } catch (Throwable $e) {
                // Without the key this user's next reads follow the normal
                // rules (the lag threshold still applies). The write itself
                // succeeded, so the response is not turned into an error.
                Log::warning('could not record the write position for read-your-writes: '.$e->getMessage());
            }
        }

        return $response;
    }
}
