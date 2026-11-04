<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\NotificationsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationsController extends Controller
{
    public function __construct(private NotificationsService $service)
    {
    }

    // POST /notifications/devices  body: { "token": "<FCM token of this browser>" }
    // Called by the front end after the user allowed notifications.
    public function registerDevice(Request $request): JsonResponse
    {
        $result = $this->service->registerDevice(
            $request->attributes->get('user'),
            $request->json()->all(),
            $request->headers->get('User-Agent'),
        );

        return ApiResponse::json($result, 201);
    }

    // DELETE /notifications/devices/:token  (on sign out: this browser stops receiving pushes)
    public function unregisterDevice(Request $request, string $token): Response
    {
        $this->service->unregisterDevice($request->attributes->get('user'), $token);

        return new Response('', 204);
    }

    // GET /notifications?page=&limit=&is_read=true|false&type=order_created|order_status_changed
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->listForUser($request->attributes->get('user'), $request->query->all()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return ApiResponse::json($this->service->markAllReadForUser($request->attributes->get('user')));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->getForUser($request->attributes->get('user'), $id));
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        return ApiResponse::json($this->service->markReadForUser($request->attributes->get('user'), $id));
    }
}
