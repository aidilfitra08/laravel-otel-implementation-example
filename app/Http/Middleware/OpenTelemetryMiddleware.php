<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

class OpenTelemetryMiddleware
{
    private static $collectorAvailable = null;
    private static $lastHealthCheck = 0;
    private const HEALTH_CHECK_INTERVAL = 60; // Check every 60 seconds

    /**
     * Check if OTEL collector is available
     */
    private function isCollectorAvailable(): bool
    {
        // If we checked recently, use cached result
        if (self::$collectorAvailable !== null && (time() - self::$lastHealthCheck) < self::HEALTH_CHECK_INTERVAL) {
            return self::$collectorAvailable;
        }

        // Quick health check with short timeout
        $endpoint = config('opentelemetry.exporter.otlp.endpoint', 'http://localhost:14318');
        $healthUrl = str_replace([':4317', ':4318'], ':13133', $endpoint);

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 1, // 1 second timeout
                    'ignore_errors' => true,
                ],
            ]);

            $result = @file_get_contents($healthUrl, false, $context);
            self::$collectorAvailable = $result !== false;
        } catch (\Exception $e) {
            self::$collectorAvailable = false;
        }

        self::$lastHealthCheck = time();
        return self::$collectorAvailable;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip telemetry if collector is not available
        if (!$this->isCollectorAvailable()) {
            return $next($request);
        }

        $startTime = microtime(true);

        // Get meter for metrics
        // $meter = app('opentelemetry.meter');

        // Create metrics if meter is available
        // if ($meter && config('opentelemetry.metrics.enabled')) {
        //     $requestCounter = $meter->createCounter(
        //         'laravel_example_http_server_requests',
        //         'requests',
        //         'Total number of HTTP requests'
        //     );
        //
        //     $requestDuration = $meter->createHistogram(
        //         'laravel_example_http_server_duration',
        //         'ms',
        //         'HTTP request duration in milliseconds'
        //     );
        // }

        if (!config('opentelemetry.enabled') || !config('opentelemetry.traces.enabled')) {
            return $next($request);
        }

        $tracer = app('opentelemetry.tracer');

        if (!$tracer) {
            return $next($request);
        }

        // Start a new span for this HTTP request
        $spanBuilder = $tracer->spanBuilder(sprintf('%s %s', $request->method(), $request->path()))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setStartTimestamp((int) (microtime(true) * 1_000_000_000));

        // Add HTTP attributes
        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        try {
            // Set span attributes
            $span->setAttribute('http.method', $request->method());
            $span->setAttribute('http.url', $request->fullUrl());
            $span->setAttribute('http.target', $request->getRequestUri());
            $span->setAttribute('http.host', $request->getHost());
            $span->setAttribute('http.scheme', $request->getScheme());
            $span->setAttribute('http.user_agent', $request->userAgent() ?? '');
            $span->setAttribute('http.client_ip', $request->ip());
            $span->setAttribute('http.route', $request->path());

            // Add custom attributes
            if ($request->route()) {
                $span->setAttribute('http.route.name', $request->route()->getName() ?? 'unnamed');
                $span->setAttribute('http.route.action', $request->route()->getActionName());
            }

            // Process the request
            $response = $next($request);

            // Record metrics
            // if ($meter && config('opentelemetry.metrics.enabled')) {
            //     $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            //     $method = $request->method();
            //     $route = $request->path();
            //     $status = $response->getStatusCode();
            //
            //     $attributes = [
            //         'http.method' => $method,
            //         'http.route' => $route,
            //         'http.status_code' => (string) $status,
            //     ];
            //
            //     $requestCounter->add(1, $attributes);
            //     $requestDuration->record($duration, $attributes);
            // }

            // Set response attributes
            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('http.response.content_length', strlen($response->getContent()));

            // Set span status based on HTTP status code
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Internal Server Error');
            } elseif ($response->getStatusCode() >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Client Error');
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            return $response;
        } catch (\Throwable $e) {
            // Record exception
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            // End span
            $span->end();
            $scope->detach();
        }
    }
}
