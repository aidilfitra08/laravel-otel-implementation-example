<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Prefix
    |--------------------------------------------------------------------------
    |
    | This value sets the prefix for all Prometheus metrics collected by
    | your application. This is useful for namespacing metrics when running
    | multiple applications or services.
    |
    | Example: 'myapp_' will prefix metrics as 'myapp_http_requests_total'
    |
    */
    'prefix' => env('PROMETHEUS_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Prometheus Storage Backend
    |--------------------------------------------------------------------------
    |
    | Supported: 'memory', 'pdo', 'redis'
    |
    | memory - In-memory storage (resets on server restart)
    | pdo    - SQLite file-based storage (persists across restarts)
    | redis  - Redis storage (persists, requires Redis extension & running server)
    |
    */
    'storage' => env('PROMETHEUS_STORAGE', 'memory'),
];
