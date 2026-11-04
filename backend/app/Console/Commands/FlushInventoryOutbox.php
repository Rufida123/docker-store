<?php

namespace App\Console\Commands;

use App\Modules\Orders\OrdersModule;
use Illuminate\Console\Command;

/**
 * Delivers the ReleaseStock calls that could not reach the inventory service
 * when they happened (cancelled orders, compensations after failed saves or
 * timeouts). Runs every minute through the scheduler; releases are idempotent
 * on the inventory side, so retrying is always safe.
 */
class FlushInventoryOutbox extends Command
{
    protected $signature = 'inventory:flush-outbox';

    protected $description = 'Retry undelivered inventory stock releases';

    public function handle(OrdersModule $orders): int
    {
        [$delivered, $pending] = $orders->flushInventoryOutbox();

        $this->line("inventory outbox: {$delivered} released, {$pending} still pending");

        return 0;
    }
}
