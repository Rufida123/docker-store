<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\UsersService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class UsersController extends Controller
{
    public function __construct(private UsersService $service)
    {
    }

    // GET /users  (admin only)
    public function index(): JsonResponse
    {
        return ApiResponse::json($this->service->listAll());
    }
}
