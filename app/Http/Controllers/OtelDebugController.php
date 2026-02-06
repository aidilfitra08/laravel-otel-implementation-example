<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class OtelDebugController extends Controller
{
    /**
     * Check OTEL collector health
     */
    public function checkCollector(): JsonResponse
    {
        $results = [];

        // Check local debug collector
        $results['local_collector'] = $this->checkEndpoint('http://localhost:23133', 'Local Debug Collector');

        // Check main collector
        $results['main_collector'] = $this->checkEndpoint('http://localhost:13133', 'Main Collector');

        // Check OTLP HTTP endpoints
        $results['local_otlp_http'] = $this->checkEndpoint('http://localhost:24318', 'Local OTLP HTTP');
        $results['main_otlp_http'] = $this->checkEndpoint('http://localhost:14318', 'Main OTLP HTTP');

        // Get current configuration
        $results['config'] = [
            'otel_enabled' => config('opentelemetry.enabled'),
            'traces_enabled' => config('opentelemetry.traces.enabled'),
            'current_endpoint' => config('opentelemetry.traces.exporter.endpoint'),
            'service_name' => config('opentelemetry.service_name'),
        ];

        return response()->json($results);
    }

    /**
     * Test sending a trace to OTEL collector
     */
    public function testTrace(): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');

        if (!$tracer) {
            return response()->json([
                'success' => false,
                'message' => 'OpenTelemetry tracer is not initialized',
                'hint' => 'Check if OTEL_ENABLED and OTEL_TRACES_ENABLED are true in .env',
            ], 500);
        }

        try {
            $span = $tracer->spanBuilder('debug_test_trace')
                ->setAttribute('test.type', 'manual_debug')
                ->setAttribute('test.timestamp', now()->toIso8601String())
                ->setAttribute('test.random_id', uniqid('test_'))
                ->startSpan();

            $scope = $span->activate();

            try {
                $span->addEvent('Debug test started', [
                    'event.time' => microtime(true),
                ]);

                // Simulate some work
                usleep(50000); // 50ms

                $span->addEvent('Debug test completed', [
                    'event.time' => microtime(true),
                ]);

                $traceId = $span->getContext()->getTraceId();
                $spanId = $span->getContext()->getSpanId();

                return response()->json([
                    'success' => true,
                    'message' => 'Test trace sent successfully',
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'endpoint' => config('opentelemetry.traces.exporter.endpoint'),
                    'instructions' => [
                        'Check logs' => 'docker logs otel-collector-debug',
                        'Check file' => 'docker exec otel-collector-debug cat /tmp/otel-traces.json',
                        'Verify in Grafana' => 'Search for trace ID: ' . $traceId,
                    ],
                ]);
            } finally {
                $span->end();
                $scope->detach();
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test trace',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Show OTEL configuration
     */
    public function showConfig(): JsonResponse
    {
        return response()->json([
            'opentelemetry' => config('opentelemetry'),
            'env_variables' => [
                'OTEL_ENABLED' => env('OTEL_ENABLED'),
                'OTEL_SERVICE_NAME' => env('OTEL_SERVICE_NAME'),
                'OTEL_EXPORTER_OTLP_ENDPOINT' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'OTEL_TRACES_ENABLED' => env('OTEL_TRACES_ENABLED'),
            ],
            'php_info' => [
                'version' => phpversion(),
                'extensions' => get_loaded_extensions(),
            ],
        ]);
    }

    /**
     * Check if an endpoint is accessible
     */
    private function checkEndpoint(string $url, string $name): array
    {
        try {
            $response = Http::timeout(5)->get($url);

            return [
                'name' => $name,
                'url' => $url,
                'status' => 'reachable',
                'http_code' => $response->status(),
                'response' => $response->successful() ? 'OK' : 'Error',
            ];
        } catch (\Exception $e) {
            return [
                'name' => $name,
                'url' => $url,
                'status' => 'unreachable',
                'error' => $e->getMessage(),
            ];
        }
    }
}
