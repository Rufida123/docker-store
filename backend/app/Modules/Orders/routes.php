<?php

// URLs of the orders module. Handlers live in the controller; the logic in the service.
// Every orders URL needs a valid token (401 otherwise).

use App\Modules\Orders\Http\Controllers\OrdersController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->group(function (): void {
    Route::post('/orders', [OrdersController::class, 'store'])->middleware('role:customer');
    Route::get('/orders', [OrdersController::class, 'index'])->middleware('role:customer,admin');
    Route::get('/orders/{id}', [OrdersController::class, 'show'])->middleware('role:customer,admin');
    Route::patch('/orders/{id}/status', [OrdersController::class, 'updateStatus'])->middleware('role:admin');
});
