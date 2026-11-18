<?php

namespace App\Shared\Messaging;

use App\Shared\Events;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Listens to the in-process events the orders module already publishes (after
 * the transaction committed) and forwards them to RabbitMQ. The orders module
 * does not know that RabbitMQ exists.
 *
 * The listeners run after the HTTP response has been sent (see Events), so the
 * customer never waits for the broker, and a broker failure never fails an order.
 */
final class OrderEventsBridge
{
    public static function subscribe(): void
    {
        Events::subscribe('order.created', fn (array $e) => self::send([
            // for the workers (email)
            [Topology::EVENTS, 'order.created', $e],
            // for the admin dashboard: ids only, the browser fetches the order over REST
            [Topology::REALTIME, 'admin.orders', ['type' => 'order.created', 'orderId' => $e['orderId']]],
        ]));

        Events::subscribe('order.status_changed', fn (array $e) => self::send([
            [Topology::EVENTS, 'order.status_changed', $e],
            // the customer who owns the order (only they may subscribe to this key)
            [Topology::REALTIME, 'order.'.$e['orderId'], ['type' => 'order.status_changed', 'orderId' => $e['orderId'], 'status' => $e['to']]],
            // every open admin dashboard
            [Topology::REALTIME, 'admin.orders', ['type' => 'order.status_changed', 'orderId' => $e['orderId'], 'status' => $e['to']]],
        ]));
    }

    private static function send(array $messages): void
    {
        if (! config('store.events.publish', true)) {
            return;
        }

        try {
            app(EventPublisher::class)->publishMany($messages);
        } catch (Throwable $e) {
            Log::error('RabbitMQ publish failed: '.$e->getMessage());
        }
    }
}