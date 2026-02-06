<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Services\PrometheusMetrics;

class MetricsController extends Controller
{
    /**
     * Expose metrics in Prometheus format
     */
    public function index(): Response
    {
        // Add application info metric
        PrometheusMetrics::setGauge('laravel_app_info', [
            'version' => config('opentelemetry.service_version', '1.0.0'),
            'environment' => config('app.env', 'production'),
            'service' => config('opentelemetry.service_name', 'laravel-app'),
        ], 1);

        $metrics = PrometheusMetrics::render();

        return response($metrics, 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Reset all metrics (like a power button)
     */
    public function reset(): JsonResponse
    {
        try {
            PrometheusMetrics::reset();

            return response()->json([
                'success' => true,
                'message' => 'Prometheus metrics cleared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
