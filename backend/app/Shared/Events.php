<?php

namespace App\Shared;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-process event bus: lets one module react to another without using it.
 * Example: orders publishes 'order.status_changed', and the notifications
 * module subscribes to it. orders never needs to know that notifications
 * exists.
 *
 * Publish only AFTER the database transaction has committed, so listeners never
 * act on data that was rolled back. Listeners run once the response has been
 * sent, and a failing listener is logged without affecting the request that
 * published the event.
 */
final class Events
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    public static function subscribe(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function publish(string $event, array $payload): void
    {
        $listeners = self::$listeners[$event] ?? [];
        if ($listeners === []) {
            return;
        }

        app()->terminating(function () use ($event, $payload, $listeners): void {
            foreach ($listeners as $listener) {
                try {
                    $listener($payload);
                } catch (Throwable $e) {
                    Log::error("Listener for \"{$event}\" failed: ".$e->getMessage());
                }
            }
        });
    }
}
