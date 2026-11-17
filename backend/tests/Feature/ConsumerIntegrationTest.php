<?php

namespace Tests\Feature;

use App\Shared\Messaging\Consumer;
use App\Shared\Messaging\RabbitConnection;
use App\Shared\Messaging\Topology;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The delivery rules of Consumer against a real RabbitMQ. Skipped when no
 * broker can be reached.
 *
 * Every test works on its own uniquely named work queue + retry queue (built by
 * Topology's own work-queue declaration, with a 1 second retry delay), so the
 * project's real queues and a running mail worker are never touched. Consumer
 * always dead-letters into the shared store.dlq: the tests only read, and
 * remove, the dead letters whose x-original-queue is their own queue.
 */
class ConsumerIntegrationTest extends TestCase
{
    private ?RabbitConnection $admin = null;

    private string $queue = '';

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('rabbitmq.connection');

        try {
            $probe = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'],
                connection_timeout: 1.0,
                read_write_timeout: 3.0,
            );
            $probe->close();
        } catch (Throwable $e) {
            $this->markTestSkipped('RabbitMQ is not reachable: '.$e->getMessage());
        }

        $this->queue = 'test.consumer.'.bin2hex(random_bytes(4));
        $this->admin = new RabbitConnection($config);

        $ch = $this->admin->channel();
        $ch->confirm_select();
        (new ReflectionMethod(Topology::class, 'declareWorkQueue'))->invoke(null, $ch, $this->queue, 1000);
        // Same declaration as Topology::declare(): safe when store.dlq already exists.
        $ch->queue_declare(Topology::DLQ, false, true, false, false);
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            try {
                $this->takeDeadLetters(); // leave none of ours in store.dlq
                $ch = $this->admin->channel();
                $ch->queue_delete($this->queue);
                $ch->queue_delete(Topology::retryQueue($this->queue));
            } catch (Throwable) {
                // Cleanup must never hide the real test result.
            }
            $this->admin->close();
        }

        parent::tearDown();
    }

    private function publish(string $body): void
    {
        $ch = $this->admin->channel();
        $ch->basic_publish(new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]), '', $this->queue);
        $ch->wait_for_pending_acks(5.0);
    }

    private function consume(callable $handler, int $stopAfter): void
    {
        $connection = new RabbitConnection(config('rabbitmq.connection'), true);

        try {
            (new Consumer($connection, 3))->run($this->queue, $handler, $stopAfter);
        } finally {
            $connection->close();
        }
    }

    /** Ready messages in a queue. (Not "count": PHPUnit's TestCase::count() is final.) */
    private function readyMessages(string $queue): int
    {
        [, $messages] = $this->admin->channel()->queue_declare($queue, true);

        return (int) $messages;
    }

    private function assertQueueEmpty(string $queue): void
    {
        for ($i = 0; $i < 20 && $this->readyMessages($queue) > 0; $i++) {
            usleep(100_000);
        }

        $this->assertSame(0, $this->readyMessages($queue), "{$queue} should be empty");
    }

    /**
     * Removes from store.dlq (and returns) the dead letters that came from this
     * test's queue. Messages of anyone else are put back.
     *
     * @return list<AMQPMessage>
     */
    private function takeDeadLetters(): array
    {
        $ch = $this->admin->channel();
        $mine = [];
        $others = [];

        for ($i = $this->readyMessages(Topology::DLQ); $i > 0; $i--) {
            $message = $ch->basic_get(Topology::DLQ, false);
            if ($message === null) {
                break;
            }

            $headers = $message->has('application_headers')
                ? $message->get('application_headers')->getNativeData()
                : [];

            if (($headers['x-original-queue'] ?? null) === $this->queue) {
                $mine[] = $message;
                $message->ack();
            } else {
                $others[] = $message;
            }
        }

        foreach ($others as $message) {
            $ch->basic_nack($message->getDeliveryTag(), false, true);
        }

        return $mine;
    }

    public function test_a_handler_that_fails_twice_then_succeeds_is_delivered_three_times(): void
    {
        $deliveries = [];
        $this->publish(json_encode(['n' => 1]));

        $this->consume(function (array $body) use (&$deliveries): void {
            $deliveries[] = $body;
            if (count($deliveries) < 3) {
                throw new RuntimeException('not yet');
            }
        }, 3);

        $this->assertCount(3, $deliveries);
        $this->assertSame(['n' => 1], $deliveries[2]);
        $this->assertQueueEmpty($this->queue);
        $this->assertQueueEmpty(Topology::retryQueue($this->queue));
        $this->assertSame([], $this->takeDeadLetters(), 'a message that finally succeeded is not dead-lettered');
    }

    public function test_a_handler_that_always_fails_ends_in_the_dead_letter_queue(): void
    {
        $calls = 0;
        $this->publish(json_encode(['n' => 2]));

        $this->consume(function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('boom');
        }, 3);

        $this->assertSame(3, $calls);
        $this->assertQueueEmpty($this->queue);
        $this->assertQueueEmpty(Topology::retryQueue($this->queue));

        $dead = $this->takeDeadLetters();
        $this->assertCount(1, $dead);
        $this->assertSame(['n' => 2], json_decode($dead[0]->getBody(), true));

        $headers = $dead[0]->get('application_headers')->getNativeData();
        $this->assertSame($this->queue, $headers['x-original-queue']);
        $this->assertStringContainsString('boom', $headers['x-error']);
        $this->assertNotEmpty($headers['x-failed-at']);
    }

    public function test_an_unreadable_message_goes_straight_to_the_dead_letter_queue(): void
    {
        $calls = 0;
        $this->publish('this is not json');

        $this->consume(function () use (&$calls): void {
            $calls++;
        }, 1);

        $this->assertSame(0, $calls, 'the handler never sees an unreadable body');
        $this->assertQueueEmpty($this->queue);
        $this->assertQueueEmpty(Topology::retryQueue($this->queue));

        $dead = $this->takeDeadLetters();
        $this->assertCount(1, $dead);
        $this->assertSame('this is not json', $dead[0]->getBody());

        $headers = $dead[0]->get('application_headers')->getNativeData();
        $this->assertSame($this->queue, $headers['x-original-queue']);
        $this->assertStringContainsString('unreadable message', $headers['x-error']);
        $this->assertNotEmpty($headers['x-failed-at']);
    }

    public function test_a_message_received_but_not_acknowledged_returns_when_its_connection_closes(): void
    {
        $this->publish(json_encode(['n' => 4]));

        $worker = new RabbitConnection(config('rabbitmq.connection'));
        $received = $worker->channel()->basic_get($this->queue, false);
        $this->assertNotNull($received, 'the worker received the message');
        $this->assertSame(0, $this->readyMessages($this->queue), 'in flight: not ready for anyone else');

        $worker->close(); // the worker dies without acknowledging

        $again = null;
        for ($i = 0; $i < 30 && $again === null; $i++) {
            $again = $this->admin->channel()->basic_get($this->queue, false);
            if ($again === null) {
                usleep(100_000);
            }
        }

        $this->assertNotNull($again, 'RabbitMQ gives the message back');
        $this->assertSame(['n' => 4], json_decode($again->getBody(), true));
        $this->assertTrue($again->delivery_info['redelivered'] ?? false, 'it is marked as redelivered');
        $again->ack();
    }
}