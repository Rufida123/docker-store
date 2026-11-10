<?php

// Application-specific settings. Read these via config() so they keep working
// when the configuration is cached.
return [

    'jwt' => [
        'secret' => env('JWT_SECRET', ''),
        'expires_in' => env('JWT_EXPIRES_IN', '15m'),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', ''),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    'uploads_dir' => env('UPLOAD_DIR') ?: base_path('uploads'),

    'inventory' => [
        'addr' => env('INVENTORY_GRPC_ADDR', 'localhost:50051'),
        'timeout_ms' => (int) env('INVENTORY_TIMEOUT_MS', 2000),
        'connect_timeout_ms' => (int) env('INVENTORY_CONNECT_TIMEOUT_MS', 500),
    ],

    // Load tests only (docs/performance.md): adds X-Query-Count / X-Query-Time-Ms headers.
    'perf' => [
        'query_log' => filter_var(env('PERF_QUERY_LOG', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Catalog cache (docs/performance.md, 6.4). 'catalog' is a Redis store
    // with short timeouts; tests use 'array'. When the store fails, the
    // products module logs a warning and reads from the database.
    'catalog_cache' => [
        'store' => env('CATALOG_CACHE_STORE', 'catalog'),
    ],

    'firebase' => [
        'service_account_file' => env('FIREBASE_SERVICE_ACCOUNT_FILE', ''),
    ],

];
