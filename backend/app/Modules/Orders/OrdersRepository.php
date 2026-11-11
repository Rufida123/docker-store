<?php

namespace App\Modules\Orders;

use App\Shared\Time;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the orders module that talks to the database.
 * Money columns are NUMERIC and come back as strings ("59.97"): exact, no
 * float rounding. Methods marked "transaction" must run inside the caller's
 * DB::transaction, which uses the same connection.
 */
class OrdersRepository
{
    private const ORDER_COLUMNS = 'id, user_id, status, total, created_at, updated_at';

    /**
     * Creates the order under its pre-generated id (the same UUID used as the
     * inventory reservation), with its total computed by PostgreSQL from the
     * order lines. (transaction)
     */
    public function insertOrder(string $orderId, string $userId, array $items): void
    {
        $prices = '{'.implode(',', array_map(fn (array $i) => $i['price'], $items)).'}';
        $quantities = '{'.implode(',', array_map(fn (array $i) => $i['quantity'], $items)).'}';

        DB::insert(
            'INSERT INTO orders (id, user_id, total)
             SELECT ?::uuid, ?, SUM(t.price * t.quantity)
               FROM unnest(?::numeric[], ?::int[]) AS t(price, quantity)',
            [$orderId, $userId, $prices, $quantities],
        );
    }

    /**
     * One INSERT for all items, whatever the number of items. (transaction)
     */
    public function insertItems(string $orderId, array $items): void
    {
        $placeholders = [];
        $params = [];
        foreach ($items as $item) {
            $placeholders[] = '(?::uuid, ?::uuid, ?, ?, ?)';
            array_push($params, $orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']);
        }

        DB::insert(
            'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
             VALUES '.implode(', ', $placeholders),
            $params,
        );
    }

    /** (transaction) */
    public function insertHistory(string $orderId, ?string $fromStatus, string $toStatus, string $changedBy): void
    {
        DB::insert(
            'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by)
             VALUES (?, ?, ?, ?)',
            [$orderId, $fromStatus, $toStatus, $changedBy],
        );
    }

    /**
     * ownerId set (customer): the order must belong to that user, checked in the
     * query itself. ownerId null (admin): any order.
     */
    public function findById(string $id, ?string $ownerId = null): ?array
    {
        $params = [$id];
        $sql = 'SELECT '.self::ORDER_COLUMNS.' FROM orders WHERE id = ?';
        if ($ownerId !== null) {
            $params[] = $ownerId;
            $sql .= ' AND user_id = ?';
        }

        $rows = DB::select($sql, $params);

        return $rows === [] ? null : self::orderRow($rows[0]);
    }

    public function findItems(string $orderId): array
    {
        $rows = DB::select(
            'SELECT product_id, product_name, unit_price, quantity,
                    unit_price * quantity AS line_total
               FROM order_items
              WHERE order_id = ?
              ORDER BY product_name',
            [$orderId],
        );

        return array_map(fn (object $row) => (array) $row, $rows);
    }

    /**
     * The items of several orders in ONE query (a list page), never one query
     * per order.
     *
     * @param list<string> $orderIds
     * @return array<string, list<array>> order id => its items, [] for none
     */
    public function findItemsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::select(
            'SELECT order_id, product_id, product_name, unit_price, quantity,
                    unit_price * quantity AS line_total
               FROM order_items
              WHERE order_id = ANY(?::uuid[])
              ORDER BY order_id, product_name',
            ['{'.implode(',', $orderIds).'}'],
        );

        $byOrder = array_fill_keys($orderIds, []);
        foreach ($rows as $row) {
            $item = (array) $row;
            $orderId = $item['order_id'];
            unset($item['order_id']);
            $byOrder[$orderId][] = $item;
        }

        return $byOrder;
    }

    public function findHistory(string $orderId): array
    {
        $rows = DB::select(
            'SELECT from_status, to_status, changed_at
               FROM order_status_history
              WHERE order_id = ?
              ORDER BY changed_at, id',
            [$orderId],
        );

        return array_map(function (object $row): array {
            $entry = (array) $row;
            $entry['changed_at'] = Time::iso($entry['changed_at']);

            return $entry;
        }, $rows);
    }

    /**
     * filters: userId and status are optional. Returns one page plus the total count.
     *
     * @return array{rows: array, total: int}
     */
    public function list(?string $userId, ?string $status, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($userId !== null) {
            $params[] = $userId;
            $where[] = 'user_id = ?';
        }
        if ($status !== null) {
            $params[] = $status;
            $where[] = 'status = ?';
        }
        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);

        $page = DB::select(
            'SELECT '.self::ORDER_COLUMNS." FROM orders {$whereSql}
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );
        $count = DB::select("SELECT COUNT(*)::int AS total FROM orders {$whereSql}", $params);

        return ['rows' => array_map(self::orderRow(...), $page), 'total' => $count[0]->total];
    }

    /**
     * Locks the order row until the transaction ends. A second status change on
     * the same order waits here, then sees the status the first one left.
     * (transaction)
     */
    public function lockById(string $id): ?array
    {
        $rows = DB::select('SELECT id, user_id, status FROM orders WHERE id = ? FOR UPDATE', [$id]);

        return $rows === [] ? null : (array) $rows[0];
    }

    /** (transaction) */
    public function updateStatus(string $id, string $status): void
    {
        DB::update('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
    }

    // ---- inventory outbox: ReleaseStock deliveries that still have to happen ----

    public function outboxAdd(string $reservationId): void
    {
        DB::insert(
            "INSERT INTO inventory_outbox (reservation_id, action) VALUES (?, 'release')
             ON CONFLICT (reservation_id) DO NOTHING",
            [$reservationId],
        );
    }

    public function outboxDelete(string $reservationId): void
    {
        DB::delete('DELETE FROM inventory_outbox WHERE reservation_id = ?', [$reservationId]);
    }

    public function outboxMarkAttempt(string $reservationId): void
    {
        DB::update('UPDATE inventory_outbox SET last_attempt_at = NOW() WHERE reservation_id = ?', [$reservationId]);
    }

    /**
     * @return list<string> pending reservation ids, oldest first
     */
    public function outboxPending(int $limit = 100): array
    {
        // Always the primary: a release parked a moment ago may not be on the
        // replica yet (the scheduler has no replica anyway; this also covers
        // a delivery from an API request).
        $rows = DB::selectFromWriteConnection(
            'SELECT reservation_id FROM inventory_outbox ORDER BY created_at LIMIT ?',
            [$limit],
        );

        return array_map(fn (object $row) => $row->reservation_id, $rows);
    }

    private static function orderRow(object $row): array
    {
        $order = (array) $row;
        $order['created_at'] = Time::iso($order['created_at']);
        $order['updated_at'] = Time::iso($order['updated_at']);

        return $order;
    }
}
