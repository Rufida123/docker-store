<?php

namespace App\Modules\Notifications;

use App\Shared\Events;
use Illuminate\Support\Facades\Log;

/**
 * Public face of the notifications module.
 * Other code may only use this class, never the classes next to it.
 */
class NotificationsModule
{
    public function __construct(private NotificationsService $service)
    {
    }

    /**
     * Called by the orders module with an open transaction. The notification
     * must roll back with the order mutation, which is why this is not an event
     * listener.
     *
     * event: { userId, orderId, type, status }
     */
    public function createForOrderEvent(array $event): ?array
    {
        return $this->service->createForOrderEvent($event);
    }

    /**
     * Whether Firebase push is configured. Resolving it also logs the push
     * status, which the startup check uses to announce it once per boot.
     */
    public function pushEnabled(): bool
    {
        return NotificationsPush::isEnabled();
    }

    /**
     * The exact one-line push status for the startup output.
     */
    public function pushStatusLine(): string
    {
        return NotificationsPush::statusLine();
    }

    /**
     * Push notifications: reacts to the events other modules publish on the
     * shared event bus. The orders module publishes them only after its
     * transaction committed, so a push is never sent for an order change that
     * was rolled back. A push failure (Firebase down, network...) never affects
     * the order itself: the notification is already stored and visible in the app.
     */
    public static function subscribePushListeners(): void
    {
        $send = function (string $orderId, string $type, string $status): void {
            try {
                app(NotificationsService::class)->pushForOrderEvent($orderId, $type, $status);
            } catch (\Throwable $e) {
                Log::error("Push for order {$orderId} ({$status}) failed: ".$e->getMessage());
            }
        };

        Events::subscribe('order.created', fn (array $event) => $send($event['orderId'], 'order_created', 'pending'));
        Events::subscribe('order.status_changed', fn (array $event) => $send($event['orderId'], 'order_status_changed', $event['to']));
    }
}
