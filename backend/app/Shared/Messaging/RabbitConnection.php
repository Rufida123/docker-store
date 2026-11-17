<?php

namespace App\Shared\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * One AMQP connection and one channel per PHP process, opened on first use.
 *
 * Web requests use short timeouts (a dead broker must never hold a PHP
 * thread for long). Workers use a heartbeat so a dead connection is noticed.
 */
final class RabbitConnection
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @param array{host:string,port:int,user:string,password:string,vhost:string} $config */
    public function __construct(private array $config, private bool $longRunning = false)
    {
    }

    public function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
            connection_timeout: 2.0,
            // Long-running: the read timeout must be at least twice the heartbeat.
            read_write_timeout: $this->longRunning ? 130.0 : 3.0,
            keepalive: $this->longRunning,
            heartbeat: $this->longRunning ? 60 : 0,
        );
        $this->channel = $this->connection->channel();

        return $this->channel;
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // Already closed: nothing to do.
        }
        $this->channel = null;
        $this->connection = null;
    }
}