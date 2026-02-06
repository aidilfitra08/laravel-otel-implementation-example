<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class TelemetryController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');

        if ($tracer) {
            $span = $tracer->spanBuilder('health_check')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $scope = $span->activate();

            try {
                $span->setAttribute('health.status', 'healthy');
                $span->addEvent('Health check performed');

                $response = [
                    'status' => 'healthy',
                    'service' => config('opentelemetry.service_name'),
                    'version' => config('opentelemetry.service_version'),
                    'environment' => config('opentelemetry.deployment_environment'),
                    'timestamp' => now()->toIso8601String(),
                    'telemetry' => [
                        'enabled' => config('opentelemetry.enabled'),
                        'traces' => config('opentelemetry.traces.enabled'),
                        'metrics' => config('opentelemetry.metrics.enabled'),
                        'logs' => config('opentelemetry.logs.enabled'),
                    ],
                ];

                return response()->json($response);
            } finally {
                $span->end();
                $scope->detach();
            }
        }

        return response()->json([
            'status' => 'healthy',
            'service' => config('opentelemetry.service_name'),
            'timestamp' => now()->toIso8601String(),
            'telemetry' => [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Test endpoint to generate traces
     */
    public function test(): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');

        if ($tracer) {
            $span = $tracer->spanBuilder('test_operation')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $scope = $span->activate();

            try {
                $span->setAttribute('test.type', 'api_test');
                $span->setAttribute('test.random_value', rand(1, 100));
                $span->addEvent('Test operation started');

                // Simulate some work
                $this->simulateWork($tracer);

                $span->addEvent('Test operation completed');

                return response()->json([
                    'success' => true,
                    'message' => 'Test endpoint executed successfully',
                    'trace_id' => $span->getContext()->getTraceId(),
                    'span_id' => $span->getContext()->getSpanId(),
                    'timestamp' => now()->toIso8601String(),
                    'data' => [
                        'random_number' => rand(1, 1000),
                        'request_id' => uniqid('req_'),
                    ],
                ]);
            } finally {
                $span->end();
                $scope->detach();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Test endpoint executed (telemetry disabled)',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Test log endpoint for Loki
     */
    public function testLog(Request $request): JsonResponse
    {
        $tracer = app('opentelemetry.tracer');
        $level = $request->input('level', 'info');
        $message = $request->input('message', 'Test log message');

        if ($tracer) {
            $span = $tracer->spanBuilder('test_log')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $scope = $span->activate();

            try {
                $span->setAttribute('log.level', $level);
                $span->setAttribute('log.message', $message);
                $span->addEvent('Logging test event', [
                    'log.level' => $level,
                    'log.message' => $message,
                ]);

                // Generate logs at different levels
                switch ($level) {
                    case 'debug':
                        Log::debug($message, [
                            'trace_id' => $span->getContext()->getTraceId(),
                            'span_id' => $span->getContext()->getSpanId(),
                            'test_data' => ['random' => rand(1, 100)],
                        ]);
                        break;
                    case 'info':
                        Log::info($message, [
                            'trace_id' => $span->getContext()->getTraceId(),
                            'span_id' => $span->getContext()->getSpanId(),
                            'test_data' => ['random' => rand(1, 100)],
                        ]);
                        break;
                    case 'warning':
                        Log::warning($message, [
                            'trace_id' => $span->getContext()->getTraceId(),
                            'span_id' => $span->getContext()->getSpanId(),
                            'test_data' => ['random' => rand(1, 100)],
                        ]);
                        break;
                    case 'error':
                        Log::error($message, [
                            'trace_id' => $span->getContext()->getTraceId(),
                            'span_id' => $span->getContext()->getSpanId(),
                            'test_data' => ['random' => rand(1, 100)],
                            'error_code' => 'TEST_ERROR',
                        ]);
                        break;
                    default:
                        Log::info($message, [
                            'trace_id' => $span->getContext()->getTraceId(),
                            'span_id' => $span->getContext()->getSpanId(),
                        ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Log generated successfully',
                    'log_level' => $level,
                    'log_message' => $message,
                    'trace_id' => $span->getContext()->getTraceId(),
                    'span_id' => $span->getContext()->getSpanId(),
                    'timestamp' => now()->toIso8601String(),
                ]);
            } finally {
                $span->end();
                $scope->detach();
            }
        }

        // Still log even if telemetry is disabled
        Log::info($message, ['level' => $level]);

        return response()->json([
            'success' => true,
            'message' => 'Log generated (telemetry disabled)',
            'log_level' => $level,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Simulate some work with nested spans
     */
    private function simulateWork($tracer): void
    {
        $span = $tracer->spanBuilder('simulate_database_query')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('db.system', 'mysql');
            $span->setAttribute('db.operation', 'SELECT');
            $span->addEvent('Database query started');

            // Simulate database query time
            usleep(rand(10000, 50000)); // 10-50ms

            $span->addEvent('Database query completed');
        } finally {
            $span->end();
            $scope->detach();
        }

        // Simulate another operation
        $span2 = $tracer->spanBuilder('simulate_external_api_call')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope2 = $span2->activate();

        try {
            $span2->setAttribute('http.method', 'GET');
            $span2->setAttribute('http.url', 'https://api.example.com/data');
            $span2->addEvent('API call started');

            // Simulate API call time
            usleep(rand(50000, 150000)); // 50-150ms

            $span2->addEvent('API call completed');
        } finally {
            $span2->end();
            $scope2->detach();
        }
    }
}
