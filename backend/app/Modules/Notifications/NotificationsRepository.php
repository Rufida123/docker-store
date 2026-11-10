<?php

namespace App\Modules\Notifications;

use App\Shared\Database\Sql;
use App\Shared\Time;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the notifications module that talks to the database.
 */
class NotificationsRepository
{
    private const COLUMNS = 'id, user_id, order_id, type, title, message, metadata, is_read, read_at, created_at';

    /**
     * Runs inside the caller's transaction (same connection).
     */
    public function insert(array $notification): ?array
    {
        $rows = Sql::returning(
            'INSERT INTO notifications (user_id, order_id, type, status, title, message, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)
             ON CONFLICT (order_id, type, status) DO NOTHING
             RETURNING '.self::COLUMNS,
            [
                $notification['userId'],
                $notification['orderId'],
                $notification['type'],
                $notification['status'],
                $notification['title'],
                $notification['message'],
                json_encode($notification['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * filters: isRead (true/false) and type are optional. `total` counts the
     * filtered list; `unread_count` is always all of the user's unread
     * notifications (for the bell badge).
     *
     * @return array{rows: array, total: int, unread_count: int}
     */
    public function list(string $userId, ?bool $isRead, ?string $type, int $limit, int $offset): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];
        if ($isRead !== null) {
            $params[] = $isRead ? 'true' : 'false'; // PDO has no clean boolean binding
            $where[] = 'is_read = ?';
        }
        if ($type !== null) {
            $params[] = $type;
            $where[] = 'type = ?';
        }
        $whereSql = implode(' AND ', $where);

        $page = DB::select(
            'SELECT '.self::COLUMNS."
               FROM notifications
              WHERE {$whereSql}
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );
        $counts = DB::select(
            "SELECT COUNT(*) FILTER (WHERE {$whereSql})::int AS total,
                    COUNT(*) FILTER (WHERE is_read = FALSE)::int AS unread_count
               FROM notifications
              WHERE user_id = ?",
            [...$params, $userId],
        );

        return [
            'rows' => array_map(self::row(...), $page),
            'total' => $counts[0]->total,
            'unread_count' => $counts[0]->unread_count,
        ];
    }

    /**
     * The notification written for one order event (order_id + type + status is unique).
     */
    public function findByEvent(string $orderId, string $type, string $status): ?array
    {
        $rows = DB::select(
            'SELECT '.self::COLUMNS.' FROM notifications WHERE order_id = ? AND type = ? AND status = ?',
            [$orderId, $type, $status],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    public function findByIdForUser(string $id, string $userId): ?array
    {
        $rows = DB::select(
            'SELECT '.self::COLUMNS.' FROM notifications WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    public function markRead(string $id, string $userId): ?array
    {
        $rows = Sql::returning(
            'UPDATE notifications
                SET is_read = TRUE, read_at = COALESCE(read_at, NOW())
              WHERE id = ? AND user_id = ?
              RETURNING '.self::COLUMNS,
            [$id, $userId],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    public function markAllRead(string $userId): int
    {
        return DB::update(
            'UPDATE notifications
                SET is_read = TRUE, read_at = NOW()
              WHERE user_id = ? AND is_read = FALSE',
            [$userId],
        );
    }

    // ---- devices (browsers that receive push notifications) ----

    /**
     * Registers a browser for a user. If the token was registered by another
     * user before (someone else signed in on this browser), it now belongs to
     * this user only.
     */
    public function saveDevice(string $token, string $userId, ?string $userAgent): void
    {
        DB::statement(
            'INSERT INTO notification_devices (token, user_id, user_agent)
             VALUES (?, ?, ?)
             ON CONFLICT (token) DO UPDATE
                SET user_id = EXCLUDED.user_id,
                    user_agent = EXCLUDED.user_agent,
                    last_seen_at = NOW()',
            [$token, $userId, $userAgent],
        );
    }

    /**
     * Only removes the token if it belongs to this user.
     */
    public function deleteDevice(string $token, string $userId): int
    {
        return DB::delete('DELETE FROM notification_devices WHERE token = ? AND user_id = ?', [$token, $userId]);
    }

    public function listDeviceTokens(string $userId): array
    {
        $rows = DB::select('SELECT token FROM notification_devices WHERE user_id = ?', [$userId]);

        return array_map(fn (object $row) => $row->token, $rows);
    }

    /**
     * Tokens Firebase reported as no longer valid (app uninstalled, permission
     * revoked...).
     */
    public function deleteDeviceTokens(array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        DB::delete(
            'DELETE FROM notification_devices WHERE token = ANY(?::text[])',
            ['{'.implode(',', array_map(self::quoteArrayElement(...), $tokens)).'}'],
        );
    }

    private static function quoteArrayElement(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private static function row(object $raw): array
    {
        $row = (array) $raw;
        // jsonb comes back as text; the API returns it as the object it is.
        $row['metadata'] = json_decode($row['metadata']);
        $row['read_at'] = Time::iso($row['read_at']);
        $row['created_at'] = Time::iso($row['created_at']);

        return $row;
    }
}
