<?php

// URLs of the products module. Handlers live in the controller; the logic in the service.

use App\Modules\Products\Http\Controllers\ProductsController;
use App\Modules\Products\Http\Middleware\UploadLimits;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductsController::class, 'index']);
Route::get('/products/{id}', [ProductsController::class, 'show']);
Route::post('/products', [ProductsController::class, 'store'])
    ->middleware(['auth.jwt', 'role:admin', UploadLimits::class]);
Route::patch('/products/{id}', [ProductsController::class, 'update'])
    ->middleware(['auth.jwt', 'role:admin']);
Route::patch('/products/{id}/stock', [ProductsController::class, 'updateStock'])
    ->middleware(['auth.jwt', 'role:admin']);
