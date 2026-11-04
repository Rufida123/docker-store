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

    'firebase' => [
        'service_account_file' => env('FIREBASE_SERVICE_ACCOUNT_FILE', ''),
    ],

];
