<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\OrdersService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function __construct(private OrdersService $service)
    {
    }

    // POST /orders  (customer only)
    // Body: { "items": [{ "product_id": "<uuid>", "quantity": 2 }] }
    public function store(Request $request): JsonResponse
    {
        $order = $this->service->createOrder($request->attributes->get('user'), $request->json()->all());

        return ApiResponse::json($order, 201, ['Location' => '/api/v1/orders/'.$order['id']]);
    }

    // GET /orders  (customer: own orders only, admin: all orders)
    // Query: ?page=1&limit=20&status=pending  (+ &user_id=<uuid> for admins)
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->listOrders($request->attributes->get('user'), $request->query->all()));
    }

    // GET /orders/:id  (customer: own order only, admin: any order)
    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->getOrder($request->attributes->get('user'), $id));
    }

    // PATCH /orders/:id/status  (admin only)  Body: { "status": "confirmed" }
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->changeStatus($request->attributes->get('user'), $id, $request->json()->all()));
    }
}
