<?php

namespace App\Modules\Notifications;

use App\Modules\Orders\OrdersModule;
use App\Modules\Users\UsersModule;
use App\Shared\AppError;
use App\Shared\Text;
use App\Shared\Validation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Business rules for persistent, user-owned notifications and their push delivery.
 */
class NotificationsService
{
    private const TYPES = ['order_created', 'order_status_changed'];

    private const MAX_TOKEN_LENGTH = 4096;

    // What the customer reads, per order status. "#1a2b3c4d" = the first 8
    // characters of the order id.
    private const TEXTS = [
        'pending' => ['title' => 'Order placed', 'message' => 'Order %s was placed and is waiting for confirmation.'],
        'confirmed' => ['title' => 'Order confirmed', 'message' => 'Order %s was confirmed.'],
        'shipped' => ['title' => 'Order shipped', 'message' => 'Order %s is on its way.'],
        'delivered' => ['title' => 'Order delivered', 'message' => 'Order %s was delivered.'],
        'cancelled' => ['title' => 'Order cancelled', 'message' => 'Order %s was cancelled.'],
    ];

    public function __construct(private NotificationsRepository $repo)
    {
    }

    private static function checkNotificationId(mixed $id): void
    {
        if (! Validation::isUuid($id)) {
            throw Validation::error([['field' => 'id', 'message' => 'id must be a valid UUID']]);
        }
    }

    /**
     * Called by the orders module inside its transaction. This is deliberately
     * not an event listener: the notification must roll back with the order
     * mutation.
     */
    public function createForOrderEvent(array $event): ?array
    {
        $text = self::TEXTS[$event['status']];

        return $this->repo->insert([
            'userId' => $event['userId'],
            'orderId' => $event['orderId'],
            'type' => $event['type'],
            'status' => $event['status'],
            'title' => $text['title'],
            'message' => sprintf($text['message'], '#'.substr($event['orderId'], 0, 8)),
            'metadata' => ['order_id' => $event['orderId'], 'status' => $event['status']],
        ]);
    }

    /**
     * GET /notifications?page=&limit=&is_read=true|false&type=order_created|order_status_changed
     */
    public function listForUser(array $user, array $query): array
    {
        $pagination = Validation::parsePagination($query);
        $details = [];

        $isRead = null;
        if (array_key_exists('is_read', $query)) {
            if ($query['is_read'] === 'true') {
                $isRead = true;
            } elseif ($query['is_read'] === 'false') {
                $isRead = false;
            } else {
                $details[] = ['field' => 'is_read', 'message' => 'is_read must be true or false'];
            }
        }
        $type = null;
        if (array_key_exists('type', $query)) {
            if (! in_array($query['type'], self::TYPES, true)) {
                $details[] = ['field' => 'type', 'message' => 'type must be one of: '.implode(', ', self::TYPES)];
            } else {
                $type = $query['type'];
            }
        }
        if ($details !== []) {
            throw Validation::error($details);
        }

        $result = $this->repo->list($user['id'], $isRead, $type, $pagination['limit'], $pagination['offset']);

        return Validation::paginated($result['rows'], $pagination, $result['total'])
            + ['unread_count' => $result['unread_count']];
    }

    public function getForUser(array $user, mixed $id): array
    {
        self::checkNotificationId($id);

        $notification = $this->repo->findByIdForUser(mb_strtolower($id), $user['id']);
        if ($notification === null) {
            throw new AppError(404, 'NOTIFICATION_NOT_FOUND', 'Notification not found');
        }

        return $notification;
    }

    public function markReadForUser(array $user, mixed $id): array
    {
        self::checkNotificationId($id);

        $notification = $this->repo->markRead(mb_strtolower($id), $user['id']);
        if ($notification === null) {
            throw new AppError(404, 'NOTIFICATION_NOT_FOUND', 'Notification not found');
        }

        return $notification;
    }

    public function markAllReadForUser(array $user): array
    {
        return ['updated' => $this->repo->markAllRead($user['id'])];
    }

    // ---- push devices ----

    private static function checkToken(mixed $token): void
    {
        if (! is_string($token)
            || Text::trim($token) === ''
            || Text::length($token) > self::MAX_TOKEN_LENGTH
            || preg_match('/(*UCP)\s/u', $token)) {
            throw Validation::error([
                ['field' => 'token', 'message' => 'token must be the FCM registration token of this browser'],
            ]);
        }
    }

    /**
     * POST /notifications/devices  body: { token }
     */
    public function registerDevice(array $user, array $body, ?string $userAgent): array
    {
        self::checkToken($body['token'] ?? null);

        $this->repo->saveDevice(
            $body['token'],
            $user['id'],
            is_string($userAgent) ? substr($userAgent, 0, 300) : null,
        );

        return ['registered' => true, 'push_enabled' => NotificationsPush::isEnabled()];
    }

    /**
     * DELETE /notifications/devices/:token (on sign out). Only the user's own
     * token is removed.
     */
    public function unregisterDevice(array $user, mixed $token): void
    {
        self::checkToken($token);

        $this->repo->deleteDevice($token, $user['id']);
    }

    /**
     * Runs AFTER the order transaction committed (see the subscriptions in
     * NotificationsModule). Sends the stored notification to every browser of
     * its owner.
     */
    public function pushForOrderEvent(string $orderId, string $type, string $status): void
    {
        if (! NotificationsPush::isEnabled()) {
            return;
        }

        $notification = $this->repo->findByEvent($orderId, $type, $status);
        if ($notification === null) {
            return;
        }

        $tokens = $this->repo->listDeviceTokens($notification['user_id']);
        if ($tokens === []) {
            return;
        }

        ['deadTokens' => $deadTokens] = NotificationsPush::sendToTokens($tokens, [
            'notification_id' => $notification['id'],
            'order_id' => $orderId,
            'type' => $type,
            'status' => $status,
            'title' => $notification['title'],
            'body' => $notification['message'],
            'url' => "/orders/{$orderId}",
        ]);
        $this->repo->deleteDeviceTokens($deadTokens);
    }

    // ---- order emails (mail worker) ----

    public function sendOrderEmail(array $message): void
    {
        // A message can be delivered twice (worker killed after sending, before
        // the ack): remember what was sent so the customer gets one email.
        $sentKey = 'mail:sent:'.$message['id'];
        if (Cache::store(config('rabbitmq.cache_store'))->has($sentKey)) {
            return;
        }

        $data = $message['data'];

        // Resolved here, not in the constructor: OrdersService already depends
        // on NotificationsModule, so injecting OrdersModule would loop.
        $order = app(OrdersModule::class)->summary($data['orderId']);
        $email = app(UsersModule::class)->emailOf($data['userId']);
        if ($order === null || $email === null) {
            throw new \RuntimeException("order {$data['orderId']} or its customer not found");
        }

        $short = '#'.substr($order['id'], 0, 8);
        $lines = array_map(
            fn (array $i) => "- {$i['quantity']} x {$i['product_name']} ({$i['unit_price']})",
            $order['items'],
        );

        if ($message['type'] === 'order.created') {
            $subject = "We received your order {$short}";
            $intro = "Thank you! Your order {$short} was placed and is waiting for confirmation.";
        } else {
            $subject = "Your order {$short} is now {$data['to']}";
            $intro = "The status of your order {$short} changed to: {$data['to']}.";
        }

        $body = $intro."\n\n".implode("\n", $lines)."\n\nTotal: {$order['total']}\n";

        Mail::raw($body, fn ($m) => $m->to($email)->subject($subject));

        Cache::store(config('rabbitmq.cache_store'))->put($sentKey, 1, 86400);
    }
}