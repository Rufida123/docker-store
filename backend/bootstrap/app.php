<?php

use App\Shared\AppError;
use App\Shared\Http\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            require base_path('routes/app.php');

            // API version 1. Every module's URLs live under /api/v1, and each
            // module owns its URLs. A future breaking change goes under /api/v2
            // while v1 keeps working for existing clients.
            Route::prefix('api/v1')->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Replace the default global stack. TrimStrings and
        // ConvertEmptyStringsToNull are left out on purpose: the API treats
        // "  " and "" as the values the client actually sent.
        $middleware->use([
            \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            \App\Shared\Http\Middleware\SecurityHeaders::class,
            \App\Shared\Http\Middleware\RejectInvalidJson::class,
            // Read-your-writes: record a user's write position before the
            // response leaves (docs/performance.md, 6.6).
            \App\Shared\Http\Middleware\RecordWrites::class,
        ]);

        $middleware->alias([
            'auth.jwt' => \App\Shared\Http\Middleware\Authenticate::class,
            'role' => \App\Shared\Http\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(AppError::class);

        // Single place that shapes every error response. Stack traces never
        // reach the client, whatever APP_DEBUG says.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof AppError) {
                return ApiResponse::error($e->status, $e->errorCode, $e->getMessage(), $e->details);
            }

            if ($e instanceof NotFoundHttpException || $e instanceof MethodNotAllowedHttpException) {
                return ApiResponse::error(404, 'NOT_FOUND', 'Route not found');
            }

            return ApiResponse::error(500, 'INTERNAL_ERROR', 'Internal server error');
        });
    })->create();
