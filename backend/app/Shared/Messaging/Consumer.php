<?php

namespace App\Shared\Messaging;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Runs one handler against one queue, forever.
 *
 * Delivery rules (this is what makes "no message is ever lost" true):
 *  - the message is acknowledged only AFTER the handler finished; if the
 *    worker is killed first, RabbitMQ delivers the message again;
 *  - a handler that throws gets the message retried: it is rejected without
 *    requeue, RabbitMQ parks it in the retry queue, and after the delay it
 *    comes back (attempts are counted from RabbitMQ's own x-death header);
 *  - after $maxAttempts failures, or for a message that can never succeed
 *    (not JSON), the message is copied to the dead-letter queue (store.dlq)
 *    with the error, and only then acknowledged.
 *
 * Handlers must be idempotent: a message can be delivered twice (worker killed
 * after sending the mail but before the ack).
 */
final class Consumer
{
    public function __construct(
        private RabbitConnection $rabbit,
        private int $maxAttempts = 3,
    ) {
    }

    /** @param callable(array, AMQPMessage): void $handler gets the decoded body */
    public function run(string $queue, callable $handler, ?int $stopAfter = null): void
    {
        $ch = $this->rabbit->channel();
        $ch->basic_qos(0, 1, false); // one unacknowledged message at a time
        $ch->confirm_select();          // the dead-letter copy is confirmed before the ack

        $seen = 0;
        $ch->basic_consume($queue, '', false, false, false, false,
            function (AMQPMessage $message) use ($queue, $handler, &$seen): void {
                $this->handle($queue, $message, $handler);
                $seen++;
            });

        while ($ch->is_consuming()) {
            $ch->wait();
            if ($stopAfter !== null && $seen >= $stopAfter) {
                return;
            }
        }
    }

    private function handle(string $queue, AMQPMessage $message, callable $handler): void
    {
        try {
            $body = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->deadLetter($queue, $message, 'unreadable message: '.$e->getMessage());

            return;
        }

        try {
            $handler($body, $message);
            $message->ack();
        } catch (Throwable $e) {
            $attempt = self::attempts($message, $queue) + 1;
            Log::warning("{$queue}: attempt {$attempt}/{$this->maxAttempts} failed: ".$e->getMessage());

            if ($attempt >= $this->maxAttempts) {
                $this->deadLetter($queue, $message, $e->getMessage());
            } else {
                $message->nack(false); // requeue=false -> dead-letter -> retry queue
            }
        }
    }

    private function deadLetter(string $queue, AMQPMessage $message, string $error): void
    {
        $copy = new AMQPMessage($message->getBody(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable([
                'x-original-queue' => $queue,
                'x-error' => mb_substr($error, 0, 500),
                'x-failed-at' => gmdate('c'),
            ]),
        ]);

        $ch = $this->rabbit->channel();
        $ch->basic_publish($copy, '', Topology::DLQ);
        $ch->wait_for_pending_acks(5.0); // the copy is safe in the DLQ...
        $message->ack();                 // ...only now is the original removed
        Log::error("{$queue}: message moved to ".Topology::DLQ.": {$error}");
    }

    /** How many times RabbitMQ has already dead-lettered this message from $queue after a rejection. */
    private static function attempts(AMQPMessage $message, string $queue): int
    {
        if (! $message->has('application_headers')) {
            return 0;
        }

        $applicationHeaders = $message->get('application_headers');
        if (! $applicationHeaders instanceof AMQPTable) {
            return 0;
        }

        $headers = $applicationHeaders->getNativeData();
        $count = 0;
        foreach ($headers['x-death'] ?? [] as $death) {
            if (($death['queue'] ?? null) === $queue && ($death['reason'] ?? null) === 'rejected') {
                $count += (int) ($death['count'] ?? 0);
            }
        }

        return $count;
    }
}