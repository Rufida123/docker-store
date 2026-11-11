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

    // Read replica routing (docs/performance.md, 6.6).
    'replica' => [
        // Cache store for the per-user write LSNs and the shared replica status.
        'store' => env('ROUTING_CACHE_STORE', 'routing'),
        // Above this replication lag every read goes to the primary.
        'max_lag_seconds' => (float) env('REPLICA_MAX_LAG_SECONDS', 5),
        // The replica's replay position is checked at most this often, shared by all requests.
        'status_ttl_ms' => (int) env('REPLICA_STATUS_TTL_MS', 200),
        // After a failed replica connection, skip it for this long (all requests).
        'down_seconds' => (int) env('REPLICA_DOWN_SECONDS', 10),
        // A user's last write LSN is kept this long (cleanup only: correctness
        // comes from comparing LSNs, and a replica lagging more is not used).
        'write_lsn_ttl' => (int) env('REPLICA_WRITE_LSN_TTL', 300),
    ],

    'firebase' => [
        'service_account_file' => env('FIREBASE_SERVICE_ACCOUNT_FILE', ''),
    ],

];
