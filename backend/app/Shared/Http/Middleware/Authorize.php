<?php

namespace App\Shared\Http\Middleware;

use App\Shared\AppError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization: are you allowed to do this? Use after the auth middleware,
 * e.g. ->middleware(['auth.jwt', 'role:admin']).
 */
class Authorize
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->attributes->get('user');

        if (! $user) {
            throw new AppError(401, 'UNAUTHORIZED', 'Authentication required');
        }

        if (! in_array($user['role'], $allowedRoles, true)) {
            throw new AppError(403, 'FORBIDDEN', 'You do not have permission to perform this action');
        }

        return $next($request);
    }
}
