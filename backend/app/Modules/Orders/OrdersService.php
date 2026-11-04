<?php

namespace App\Modules\Orders;

use App\Modules\Notifications\NotificationsModule;
use App\Modules\Products\ProductsModule;
use App\Shared\AppError;
use App\Shared\Events;
use App\Shared\Validation;
use Illuminate\Support\Facades\DB;

/**
 * Business rules of the orders module (validation, stock, ownership, status
 * changes). Other modules are only reached through their public classes.
 */
class OrdersService
{
    private const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

    // Allowed status changes. delivered and cancelled are final.
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    private const MAX_ITEMS = 50;

    private const MAX_QUANTITY = 100;

    public function __construct(
        private OrdersRepository $repo,
        private ProductsModule $products,
        private NotificationsModule $notifications,
    ) {
    }

    private static function checkOrderId(mixed $id): void
    {
        if (! Validation::isUuid($id)) {
            throw Validation::error([['field' => 'id', 'message' => 'id must be a valid UUID']]);
        }
    }

    /**
     * Body: { items: [{ product_id, quantity }] }. Anything else (price,
     * user_id, status...) is ignored: prices come from the database and the
     * owner comes from the token. The same product listed twice is merged into
     * one line.
     */
    private static function parseItems(array $body): array
    {
        $items = $body['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            throw Validation::error([
                ['field' => 'items', 'message' => 'items must be an array of 1 to '.self::MAX_ITEMS.' entries'],
            ]);
        }

        $details = [];
        $merged = []; // product_id -> quantity

        foreach ($items as $i => $item) {
            $rawId = is_array($item) ? ($item['product_id'] ?? null) : null;
            $productId = is_string($rawId) ? mb_strtolower($rawId) : null;
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;

            $isInteger = is_int($quantity)
                || (is_float($quantity) && is_finite($quantity) && floor($quantity) === $quantity);

            if (! Validation::isUuid($productId)) {
                $details[] = ['field' => "items[{$i}].product_id", 'message' => 'product_id must be a valid UUID'];
            }
            if (! $isInteger || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                $details[] = [
                    'field' => "items[{$i}].quantity",
                    'message' => 'quantity must be an integer between 1 and '.self::MAX_QUANTITY,
                ];
            }
            if (Validation::isUuid($productId) && $isInteger) {
                $merged[$productId] = ($merged[$productId] ?? 0) + (int) $quantity;
            }
        }

        if ($details !== []) {
            throw Validation::error($details);
        }

        $parsed = [];
        foreach ($merged as $productId => $quantity) {
            $parsed[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return $parsed;
    }

    /**
     * Full order: header + items + status history. Call only after the access check.
     */
    private function loadOrder(array $order): array
    {
        $order['items'] = $this->repo->findItems($order['id']);
        $order['history'] = $this->repo->findHistory($order['id']);

        return $order;
    }

    /**
     * POST /orders
     * One transaction: reserve stock, create the order, its items and its first
     * history row. If any step fails (unknown product, not enough stock...)
     * nothing is saved.
     */
    public function createOrder(array $user, array $body): array
    {
        $items = self::parseItems($body);

        $orderId = DB::transaction(function () use ($user, $items): string {
            $reserved = $this->products->reserveStock($items);
            $id = $this->repo->insertOrder($user['id'], $reserved);
            $this->repo->insertItems($id, $reserved);
            $this->repo->insertHistory($id, null, 'pending', $user['id']);
            $this->notifications->createForOrderEvent([
                'userId' => $user['id'],
                'orderId' => $id,
                'type' => 'order_created',
                'status' => 'pending',
            ]);

            return $id;
        });

        $order = $this->loadOrder($this->repo->findById($orderId));
        Events::publish('order.created', ['orderId' => $orderId, 'userId' => $user['id'], 'total' => $order['total']]);

        return $order;
    }

    /**
     * GET /orders/:id
     * A customer only ever gets their own orders: the owner check is part of the
     * SQL query. Someone else's order returns the same 404 as a missing one, so
     * order ids can't be probed.
     */
    public function getOrder(array $user, mixed $id): array
    {
        self::checkOrderId($id);

        $ownerId = $user['role'] === 'admin' ? null : $user['id'];
        $order = $this->repo->findById(mb_strtolower($id), $ownerId);
        if ($order === null) {
            throw new AppError(404, 'ORDER_NOT_FOUND', 'Order not found');
        }

        return $this->loadOrder($order);
    }

    /**
     * GET /orders?page=&limit=&status=&user_id=
     * Customers always get their own orders (a user_id in the query is ignored).
     * Admins get all orders and may filter by user_id.
     */
    public function listOrders(array $user, array $query): array
    {
        $pagination = Validation::parsePagination($query);

        $status = null;
        if (array_key_exists('status', $query)) {
            if (! in_array($query['status'], self::STATUSES, true)) {
                throw Validation::error([
                    ['field' => 'status', 'message' => 'status must be one of: '.implode(', ', self::STATUSES)],
                ]);
            }
            $status = $query['status'];
        }

        $userId = null;
        if ($user['role'] === 'admin') {
            if (array_key_exists('user_id', $query)) {
                if (! Validation::isUuid($query['user_id'])) {
                    throw Validation::error([['field' => 'user_id', 'message' => 'user_id must be a valid UUID']]);
                }
                $userId = mb_strtolower($query['user_id']);
            }
        } else {
            $userId = $user['id'];
        }

        ['rows' => $rows, 'total' => $total] = $this->repo->list($userId, $status, $pagination['limit'], $pagination['offset']);

        return Validation::paginated($rows, $pagination, $total);
    }

    /**
     * PATCH /orders/:id/status (admin only, enforced in the routes)
     */
    public function changeStatus(array $user, mixed $id, array $body): array
    {
        self::checkOrderId($id);

        $status = $body['status'] ?? null;
        if (! in_array($status, self::STATUSES, true)) {
            throw Validation::error([
                ['field' => 'status', 'message' => 'status must be one of: '.implode(', ', self::STATUSES)],
            ]);
        }

        $change = DB::transaction(function () use ($user, $id, $status): array {
            // Row lock: if two admins change the same order at once, the second
            // waits and is checked against the first one's result (e.g. an order
            // can't be cancelled twice).
            $order = $this->repo->lockById(mb_strtolower($id));
            if ($order === null) {
                throw new AppError(404, 'ORDER_NOT_FOUND', 'Order not found');
            }

            $allowed = self::TRANSITIONS[$order['status']];
            if (! in_array($status, $allowed, true)) {
                throw new AppError(
                    409,
                    'INVALID_STATUS_TRANSITION',
                    "Cannot change order status from {$order['status']} to {$status}",
                    [
                        [
                            'field' => 'status',
                            'message' => $allowed !== []
                                ? 'Allowed next statuses: '.implode(', ', $allowed)
                                : "{$order['status']} is a final status",
                        ],
                    ],
                );
            }

            if ($status === 'cancelled') {
                // Put the reserved stock back, in the same transaction as the status change.
                $items = $this->repo->findItems($order['id']);
                $this->products->releaseStock($items);
            }

            $this->repo->updateStatus($order['id'], $status);
            $this->repo->insertHistory($order['id'], $order['status'], $status, $user['id']);
            $this->notifications->createForOrderEvent([
                'userId' => $order['user_id'],
                'orderId' => $order['id'],
                'type' => 'order_status_changed',
                'status' => $status,
            ]);

            return [
                'orderId' => $order['id'],
                'userId' => $order['user_id'],
                'from' => $order['status'],
                'to' => $status,
                'changedBy' => $user['id'],
            ];
        });

        Events::publish('order.status_changed', $change);

        return $this->loadOrder($this->repo->findById($change['orderId']));
    }
}
