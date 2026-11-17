<?php

namespace App\Shared\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Every exchange, queue and binding, declared in one place. Declaring is
 * idempotent: running it on every start is safe. (Changing the arguments of a
 * queue that already exists makes RabbitMQ refuse the declaration: delete the
 * queue in the management UI first.)
 *
 *   store.events    (topic)  internal domain events for workers
 *   store.realtime  (topic)  messages browsers may subscribe to (Web-STOMP)
 *
 *   mail.order_events        main queue of the mail worker
 *   mail.order_events.retry  waiting room: after the delay the message goes back
 *   store.dlq                dead letters: messages that failed for good
 */
final class Topology
{
    public const EVENTS = 'store.events';

    public const REALTIME = 'store.realtime';

    public const MAIL_QUEUE = 'mail.order_events';

    public const DLQ = 'store.dlq';

    public static function retryQueue(string $queue): string
    {
        return $queue.'.retry';
    }

    public static function declare(AMQPChannel $ch, int $retryDelayMs = 15000): void
    {
        $ch->exchange_declare(self::EVENTS, 'topic', false, true, false);
        $ch->exchange_declare(self::REALTIME, 'topic', false, true, false);

        // Dead letters: one queue for everything that failed for good.
        $ch->queue_declare(self::DLQ, false, true, false, false);

        self::declareWorkQueue($ch, self::MAIL_QUEUE, $retryDelayMs);
        $ch->queue_bind(self::MAIL_QUEUE, self::EVENTS, 'order.created');
        $ch->queue_bind(self::MAIL_QUEUE, self::EVENTS, 'order.status_changed');
    }

    /**
     * A work queue plus its retry queue:
     *   main --(consumer rejects)--> retry --(TTL expires)--> main
     * Both hops are done by RabbitMQ itself (dead-lettering through the
     * default exchange), so nothing is lost if the worker dies in between.
     */
    private static function declareWorkQueue(AMQPChannel $ch, string $queue, int $retryDelayMs): void
    {
        $retry = self::retryQueue($queue);

        $ch->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $retry,
        ]));

        $ch->queue_declare($retry, false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => $retryDelayMs,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $queue,
        ]));
    }
}