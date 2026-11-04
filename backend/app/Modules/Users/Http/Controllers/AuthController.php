<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\UsersService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private UsersService $service)
    {
    }

    // POST /auth/register  (public, creates a customer)
    public function register(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->register($request->json()->all()), 201);
    }

    // POST /auth/login  (public, rate limited)
    public function login(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->login($request->json()->all()));
    }

    // GET /auth/me  (any logged-in user)
    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return ApiResponse::json($this->service->getById($user['id']));
    }
}
