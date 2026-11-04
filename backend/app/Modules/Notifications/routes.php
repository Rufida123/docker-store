<?php

// URLs of the notifications module. Every query is scoped to the authenticated user.

use App\Modules\Notifications\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', 'role:customer,admin'])->group(function (): void {
    Route::post('/notifications/devices', [NotificationsController::class, 'registerDevice']);
    Route::delete('/notifications/devices/{token}', [NotificationsController::class, 'unregisterDevice']);
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    Route::get('/notifications/{id}', [NotificationsController::class, 'show']);
    Route::patch('/notifications/{id}/read', [NotificationsController::class, 'markRead']);
});
