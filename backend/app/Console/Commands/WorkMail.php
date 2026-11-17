<?php

namespace App\Console\Commands;

use App\Modules\Notifications\NotificationsModule;
use App\Shared\Messaging\Consumer;
use App\Shared\Messaging\RabbitConnection;
use App\Shared\Messaging\Topology;
use Illuminate\Console\Command;

class WorkMail extends Command
{
    protected $signature = 'rabbitmq:work-mail';

    protected $description = 'Consume mail.order_events and send the emails (runs until stopped)';

    public function handle(NotificationsModule $notifications): int
    {
        $config = config('rabbitmq');

        $rabbit = new RabbitConnection($config['connection'], longRunning: true);
        Topology::declare($rabbit->channel(), $config['retry_delay_ms']);
        $this->info('Mail worker waiting for messages on '.Topology::MAIL_QUEUE);

        (new Consumer($rabbit, $config['max_attempts']))->run(
            Topology::MAIL_QUEUE,
            fn (array $message) => $notifications->sendOrderEmail($message),
        );

        // Only reached when the channel was closed: exit with an error so
        // Docker (restart: unless-stopped) starts a fresh worker.
        return self::FAILURE;
    }
}