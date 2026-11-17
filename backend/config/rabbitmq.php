<?php

return [
    'connection' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER'),
        'password' => env('RABBITMQ_PASSWORD'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
    // After this many failed attempts a message goes to the dead-letter queue.
    'max_attempts' => (int) env('RABBITMQ_MAX_ATTEMPTS', 3),
    // How long a failed message waits before the next attempt.
    'retry_delay_ms' => (int) env('RABBITMQ_RETRY_DELAY_MS', 15000),
    // Cache store for the sent-mail memory and the real-time roles (Redis; the tests use "array").
    'cache_store' => env('REALTIME_CACHE_STORE', 'realtime'),
];