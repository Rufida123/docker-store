<?php

// URLs of the notifications module. Every query is scoped to the authenticated user.

use App\Modules\Notifications\Http\Controllers\NotificationsController;
use App\Modules\Notifications\Http\Controllers\RealtimeAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', 'role:customer,admin'])->group(function (): void {
    Route::post('/notifications/devices', [NotificationsController::class, 'registerDevice']);
    Route::delete('/notifications/devices/{token}', [NotificationsController::class, 'unregisterDevice']);
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    Route::get('/notifications/{id}', [NotificationsController::class, 'show']);
    Route::patch('/notifications/{id}/read', [NotificationsController::class, 'markRead']);
});

// RabbitMQ asks Laravel who may connect and subscribe (private port 3001, see
// the Caddyfile). No auth.jwt here: RabbitMQ sends no Authorization header,
// the token arrives in the form body and is checked by the controller.
Route::prefix('internal/rabbitmq/auth')->group(function () {
    Route::post('user', [RealtimeAuthController::class, 'user']);
    Route::post('vhost', [RealtimeAuthController::class, 'vhost']);
    Route::post('resource', [RealtimeAuthController::class, 'resource']);
    Route::post('topic', [RealtimeAuthController::class, 'topic']);
});