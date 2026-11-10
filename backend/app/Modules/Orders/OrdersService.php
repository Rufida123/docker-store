<?php

namespace App\Modules\Orders;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Notifications\NotificationsModule;
use App\Modules\Products\ProductsModule;
use App\Shared\AppError;
use App\Shared\Events;
use App\Shared\InventoryError;
use App\Shared\InventoryTimeout;
use App\Shared\InventoryUnavailable;
use App\Shared\Validation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Business rules of the orders module (validation, ownership, status changes,
 * and the reserve-then-save flow against the inventory service). Other
 * modules are only reached through their public classes.
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
        private InventoryModule $inventory,
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
     * Tries to give a reservation back to the inventory. A release that can't
     * be delivered right now is parked in the outbox and retried by the
     * scheduler; releasing is idempotent on the inventory side, so retrying
     * is always safe.
     */
    private function releaseOrPark(string $reservationId): void
    {
        try {
            $this->inventory->release($reservationId);
        } catch (Throwable $e) {
            $this->repo->outboxAdd($reservationId);
            Log::warning("release of reservation {$reservationId} failed, parked in the outbox: ".$e->getMessage());
        }
    }

    /**
     * POST /orders
     * The flow: validate, price the lines, reserve ALL the stock with ONE
     * inventory call (the order's UUID is the reservation id), and only then
     * save the order. A reservation whose order could not be saved is given
     * back (compensation).
     */
    public function createOrder(array $user, array $body): array
    {
        $items = self::parseItems($body);
        $lines = $this->products->getOrderLines($items); // 404 for unknown products

        $orderId = (string) Str::uuid();

        try {
            $result = $this->inventory->reserve($orderId, $items);
        } catch (InventoryUnavailable) {
            throw new AppError(503, 'INVENTORY_UNAVAILABLE', 'The inventory service is unavailable right now. Your order was not placed. Please try again in a moment.');
        } catch (InventoryTimeout) {
            // The reserve may or may not have landed; a compensating release
            // settles it either way, thanks to the tombstone. It goes through
            // the outbox (the scheduler delivers it) instead of a second
            // synchronous call, so the user never waits for two deadlines.
            $this->repo->outboxAdd($orderId);
            throw new AppError(504, 'INVENTORY_TIMEOUT', 'The inventory service did not respond in time. Your order was not placed. Please try again.');
        }

        if ($result['status'] === 'refused') {
            $shortage = $result['shortage'];
            throw new AppError(409, 'INSUFFICIENT_STOCK', 'Not enough stock for this order', [
                [
                    'field' => 'items',
                    'product_id' => $shortage['product_id'],
                    'requested' => $shortage['requested'],
                    'available' => $shortage['available'],
                    'message' => "Only {$shortage['available']} left in stock",
                ],
            ]);
        }
        if ($result['status'] !== 'reserved') {
            throw new InventoryError("reservation {$orderId} answered with ".$result['status']);
        }

        try {
            DB::transaction(function () use ($orderId, $user, $lines): void {
                $this->repo->insertOrder($orderId, $user['id'], $lines);
                $this->repo->insertItems($orderId, $lines);
                $this->repo->insertHistory($orderId, null, 'pending', $user['id']);
                $this->notifications->createForOrderEvent([
                    'userId' => $user['id'],
                    'orderId' => $orderId,
                    'type' => 'order_created',
                    'status' => 'pending',
                ]);
            });
        } catch (Throwable $e) {
            // Saving failed after the stock was taken: give it back.
            $this->releaseOrPark($orderId);
            throw $e;
        }

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
     * Every order in the page includes its items (not its history).
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

        // Each order carries its items, all fetched in one query for the
        // whole page: 3 queries per request whatever the page size.
        $items = $this->repo->findItemsForOrders(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['items'] = $items[$row['id']] ?? [];
        }
        unset($row);

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
                // The release is recorded while the row is still locked, so it
                // survives a crash between commit and delivery. Only the cancel
                // that wins the race gets here, so stock is released once.
                $this->repo->outboxAdd($order['id']);
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

        if ($change['to'] === 'cancelled') {
            $this->deliverRelease($change['orderId']);
        }

        Events::publish('order.status_changed', $change);

        return $this->loadOrder($this->repo->findById($change['orderId']));
    }

    /**
     * Delivers one outbox release now; the row stays for the scheduler when
     * the inventory can't be reached.
     */
    private function deliverRelease(string $reservationId): void
    {
        try {
            $this->inventory->release($reservationId);
            $this->repo->outboxDelete($reservationId);
        } catch (Throwable $e) {
            $this->repo->outboxMarkAttempt($reservationId);
            Log::warning("release of reservation {$reservationId} failed, kept in the outbox: ".$e->getMessage());
        }
    }

    /**
     * Retries every parked release. Returns [delivered, pending-after].
     *
     * @return array{int, int}
     */
    public function flushOutbox(): array
    {
        $pending = $this->repo->outboxPending();
        $delivered = 0;

        foreach ($pending as $reservationId) {
            try {
                $this->inventory->release($reservationId); // idempotent
                $this->repo->outboxDelete($reservationId);
                $delivered++;
            } catch (Throwable $e) {
                $this->repo->outboxMarkAttempt($reservationId);
                Log::warning("outbox release of {$reservationId} failed again: ".$e->getMessage());
            }
        }

        return [$delivered, count($pending) - $delivered];
    }
}
