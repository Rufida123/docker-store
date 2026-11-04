<?php

// URLs of the users module. Handlers live in the controllers; the logic in the service.

use App\Modules\Users\Http\Controllers\AuthController;
use App\Modules\Users\Http\Controllers\UsersController;
use App\Modules\Users\Http\Middleware\LoginRateLimiter;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware(LoginRateLimiter::class);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth.jwt');
Route::get('/users', [UsersController::class, 'index'])->middleware(['auth.jwt', 'role:admin']);
