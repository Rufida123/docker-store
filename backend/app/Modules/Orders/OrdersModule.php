<?php

namespace App\Modules\Orders;

/**
 * Public face of the orders module.
 * Other code may only use this class, never the classes next to it.
 *
 * Events this module publishes on the shared event bus (App\Shared\Events),
 * always after the change has been committed. Persistent order notifications
 * are written inside the order transaction through NotificationsModule instead:
 *   'order.created'         { orderId, userId, total }
 *   'order.status_changed'  { orderId, userId, from, to, changedBy }
 * Other modules (e.g. notifications) subscribe to these instead of using orders.
 */
class OrdersModule
{
    public function __construct(private OrdersService $service)
    {
    }

    /**
     * Retries every inventory release that could not be delivered yet
     * (cancelled orders, compensations). Used by inventory:flush-outbox.
     *
     * @return array{int, int} [delivered, still pending]
     */
    public function flushInventoryOutbox(): array
    {
        return $this->service->flushOutbox();
    }
}
