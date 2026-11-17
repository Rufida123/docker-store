<?php

namespace App\Shared\Messaging;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes messages with publisher confirms: publishMany() returns only when
 * RabbitMQ has taken responsibility for every message (written to disk, since
 * the messages are persistent and the queues durable). If the broker is
 * unreachable it throws, and the caller decides what to do (the order bridge
 * logs it: the order itself is already committed).
 */
final class EventPublisher
{
    public function __construct(private RabbitConnection $rabbit)
    {
    }

    /**
     * @param list<array{0:string,1:string,2:array}> $messages [exchange, routingKey, data]
     */
    public function publishMany(array $messages): void
    {
        $ch = $this->rabbit->channel();
        $ch->confirm_select();

        foreach ($messages as [$exchange, $routingKey, $data]) {
            $id = self::uuid();
            $body = json_encode([
                'id' => $id,
                'type' => $routingKey,
                'occurred_at' => gmdate('c'),
                'data' => $data,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            $ch->basic_publish(new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $id,
                'type' => $routingKey,
                'timestamp' => time(),
            ]), $exchange, $routingKey);
        }

        $ch->wait_for_pending_acks(5.0);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}