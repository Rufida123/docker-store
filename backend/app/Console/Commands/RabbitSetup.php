<?php

namespace App\Console\Commands;

use App\Shared\Messaging\RabbitConnection;
use App\Shared\Messaging\Topology;
use Illuminate\Console\Command;

class RabbitSetup extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Declare the RabbitMQ exchanges, queues and bindings (safe to repeat)';

    public function handle(): int
    {
        $config = config('rabbitmq');

        $rabbit = new RabbitConnection($config['connection']);
        Topology::declare($rabbit->channel(), $config['retry_delay_ms']);
        $rabbit->close();

        $this->info('RabbitMQ topology ready');

        return self::SUCCESS;
    }
}