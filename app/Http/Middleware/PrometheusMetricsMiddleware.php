<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\PrometheusMetrics;
use Throwable;

class PrometheusMetricsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $method = $request->getMethod();
        $routeLabel = $this->resolveRouteLabel($request);

        $baseLabels = [
            'method' => $method,
            'route' => $routeLabel,
        ];

        PrometheusMetrics::incrementGauge('http_requests_in_progress', $baseLabels, 1);

        $statusCode = 500;

        try {
            /** @var Response $response */
            $response = $next($request);
            $statusCode = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $duration = microtime(true) - $startTime;
            $labels = array_merge($baseLabels, ['status' => (string)$statusCode]);

            PrometheusMetrics::incrementCounter('http_requests_total', $labels, 1);
            PrometheusMetrics::observeHistogram('http_request_duration_seconds', $labels, $duration);
            PrometheusMetrics::decrementGauge('http_requests_in_progress', $baseLabels, 1);
        }
    }

    private function resolveRouteLabel(Request $request): string
    {
        // Try to get route name first
        $route = $request->route();

        if ($route !== null) {
            $name = $route->getName();
            if (!empty($name)) {
                return $name;
            }

            // Fallback to route pattern
            $uri = $route->uri();
            if (!empty($uri)) {
                return $uri;
            }
        }

        // Last resort: use the request path as label
        $path = $request->getPathInfo();
        return !empty($path) ? trim($path, '/') : 'unknown';
    }
}
