<?php

use App\Http\Controllers\DocsController;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Middleware\DocsContentSecurityPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::json(['status' => 'ok']));

// Uploaded product images (static files, not part of the versioned API).
Route::get('/uploads/products/{file}', [\App\Modules\Products\Http\Controllers\ImagesController::class, 'show'])
    ->where('file', '[^/]+');

// The OpenAPI document remains a standalone YAML file so it can be used by
// tools outside this application as well as by Swagger UI. It is registered
// outside the docs CSP group: only the UI pages need the relaxed policy.
Route::get('/api-docs/openapi.yaml', [DocsController::class, 'spec']);

Route::middleware(DocsContentSecurityPolicy::class)->group(function (): void {
    Route::get('/api-docs', [DocsController::class, 'index']);
    Route::get('/api-docs/{path}', [DocsController::class, 'asset'])->where('path', '.+');
});
