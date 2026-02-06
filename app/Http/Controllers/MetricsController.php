<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

        // Add health check metrics
        $this->addHealthMetrics();

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

    /**
     * Check database connectivity
     */
    private function checkDatabase(): int
    {
        try {
            DB::connection()->getPdo();
            return 1;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Add health check metrics for all services
     */
    private function addHealthMetrics(): void
    {
        $availableServices = ['database' /*, 'redis', 'rabbitmq', 's3' */];
        try {
            $healthData = [
                'database' => $this->checkDatabase(),
                // Add other health checks as needed:
                // 'redis' => $this->checkRedis(),
                // 'rabbitmq' => $this->checkRabbitMQ(),
                // 's3' => $this->checkS3(),
            ];

            foreach ($healthData as $serviceName => $result) {
                PrometheusMetrics::setGauge('health_check', [
                    'service' => $serviceName,
                ], $result);
            }
        } catch (\Exception $e) {
            foreach ($availableServices as $serviceName) {
                PrometheusMetrics::setGauge('health_check', [
                    'service' => $serviceName,
                ], 0);
            }
        }
    }
}
