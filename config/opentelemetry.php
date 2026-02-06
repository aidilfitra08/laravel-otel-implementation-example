<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenTelemetry instrumentation
    |
    */

    'enabled' => env('OTEL_ENABLED', true),

    'service_name' => env('OTEL_SERVICE_NAME', 'laravel-app'),

    'service_version' => env('OTEL_SERVICE_VERSION', '1.0.0'),

    'deployment_environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | OTLP Exporter Configuration
    |--------------------------------------------------------------------------
    */

    'exporter' => [
        'otlp' => [
            // OTLP endpoint (gRPC or HTTP)
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:14318'),

            // Protocol: grpc or http/protobuf
            'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),

            // Headers for authentication if needed
            'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),

            // Timeout in seconds
            'timeout' => env('OTEL_EXPORTER_OTLP_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Traces Configuration
    |--------------------------------------------------------------------------
    */

    'traces' => [
        'enabled' => env('OTEL_TRACES_ENABLED', true),

        // Sampling ratio (0.0 to 1.0)
        'sampler' => [
            'ratio' => env('OTEL_TRACES_SAMPLER_RATIO', 1.0),
        ],

        // Traces exporter endpoint
        'exporter' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:14318') . '/v1/traces'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'enabled' => env('OTEL_METRICS_ENABLED', true),

        // Metrics exporter endpoint
        'exporter' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:14318') . '/v1/metrics'),
        ],

        // Export interval in milliseconds
        'export_interval' => env('OTEL_METRICS_EXPORT_INTERVAL', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs Configuration
    |--------------------------------------------------------------------------
    */

    'logs' => [
        'enabled' => env('OTEL_LOGS_ENABLED', true),

        // Logs exporter endpoint
        'exporter' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:14318') . '/v1/logs'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Attributes
    |--------------------------------------------------------------------------
    */

    'resource_attributes' => [
        'service.name' => env('OTEL_SERVICE_NAME', 'laravel-app'),
        'service.version' => env('OTEL_SERVICE_VERSION', '1.0.0'),
        'deployment.environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),
        'host.name' => gethostname(),
    ],
];
