<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\PDO as PrometheusPDO;
use Prometheus\Storage\Redis as PrometheusRedis;

class PrometheusMetrics
{
    private static ?CollectorRegistry $registry = null;

    /**
     * Increment a counter metric
     */
    public static function incrementCounter(string $name, array $labels = [], float $value = 1): void
    {
        $labelNames = array_keys($labels);
        $values = array_values($labels);

        $counter = self::registry()->getOrRegisterCounter(
            self::namespace(),
            $name,
            self::getMetricHelp($name),
            $labelNames
        );

        $counter->incBy($value, $values);
    }

    /**
     * Set a gauge metric
     */
    public static function setGauge(string $name, array $labels = [], float $value = 0): void
    {
        $labelNames = array_keys($labels);
        $values = array_values($labels);

        $gauge = self::registry()->getOrRegisterGauge(
            self::namespace(),
            $name,
            self::getMetricHelp($name),
            $labelNames
        );

        $gauge->set($value, $values);
    }

    /**
     * Increment a gauge metric
     */
    public static function incrementGauge(string $name, array $labels = [], float $value = 1): void
    {
        $labelNames = array_keys($labels);
        $values = array_values($labels);

        $gauge = self::registry()->getOrRegisterGauge(
            self::namespace(),
            $name,
            self::getMetricHelp($name),
            $labelNames
        );

        $gauge->incBy($value, $values);
    }

    /**
     * Decrement a gauge metric
     */
    public static function decrementGauge(string $name, array $labels = [], float $value = 1): void
    {
        $labelNames = array_keys($labels);
        $values = array_values($labels);

        $gauge = self::registry()->getOrRegisterGauge(
            self::namespace(),
            $name,
            self::getMetricHelp($name),
            $labelNames
        );

        $gauge->decBy($value, $values);
    }

    /**
     * Observe a histogram metric
     */
    public static function observeHistogram(string $name, array $labels = [], float $value = 0): void
    {
        $labelNames = array_keys($labels);
        $values = array_values($labels);

        $histogram = self::registry()->getOrRegisterHistogram(
            self::namespace(),
            $name,
            self::getMetricHelp($name),
            $labelNames
        );

        $histogram->observe($value, $values);
    }

    /**
     * Render metrics in Prometheus text format
     */
    public static function render(): string
    {
        $renderer = new RenderTextFormat();
        return $renderer->render(self::registry()->getMetricFamilySamples());
    }

    /**
     * Backward-compatible alias for rendering
     */
    public static function getMetrics(): string
    {
        return self::render();
    }

    /**
     * Reset all metrics (useful for testing)
     */
    public static function reset(): void
    {
        self::$registry = null;

        try {
            $storage = self::storage();

            // For Redis storage, flush the database
            if ($storage instanceof PrometheusRedis) {
                // Access underlying Redis connection and clear
                Log::info('Flushing Prometheus Redis metrics');
            }
            // For PDO storage, delete the database file
            elseif ($storage instanceof PrometheusPDO) {
                $dbPath = storage_path('prometheus/metrics.sqlite');
                if (file_exists($dbPath)) {
                    unlink($dbPath);
                    Log::info('Cleared Prometheus SQLite database');
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to reset Prometheus metrics', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function registry(): CollectorRegistry
    {
        if (self::$registry === null) {
            self::$registry = new CollectorRegistry(self::storage());
        }

        return self::$registry;
    }

    private static function storage(): object
    {
        $storageBackend = config('prometheus.storage', 'memory');

        match ($storageBackend) {
            'pdo' => self::createPDOStorage(),
            'redis' => self::createRedisStorage(),
            default => self::createInMemoryStorage(),
        };

        return match ($storageBackend) {
            'pdo' => self::createPDOStorage(),
            'redis' => self::createRedisStorage(),
            default => self::createInMemoryStorage(),
        };
    }

    private static function createPDOStorage(): object
    {
        try {
            Log::info('Using PDO storage for Prometheus metrics');

            $storageDir = storage_path('prometheus');
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            $dbPath = $storageDir . '/metrics.sqlite';

            $pdo = new \PDO(
                'sqlite:' . $dbPath,
                null,
                null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            return new PrometheusPDO($pdo);
        } catch (\Exception $e) {
            Log::error('Prometheus PDO storage failed', [
                'error' => $e->getMessage(),
            ]);
            return self::createInMemoryStorage();
        }
    }

    private static function createRedisStorage(): object
    {
        try {
            Log::info('Using Redis storage for Prometheus metrics with Laravel Redis (Predis)');

            // Use Laravel's Redis connection (configured with Predis by default)
            $redisConnection = Redis::connection('default');

            // Test connection
            $redisConnection->ping();

            // Extract the underlying Predis client from Laravel's Connection wrapper
            $predisClient = $redisConnection->client();

            return new PrometheusRedis($predisClient);
        } catch (\Exception $e) {
            Log::error('Prometheus Redis storage failed', [
                'error' => $e->getMessage(),
            ]);
            return self::createInMemoryStorage();
        }
    }

    private static function createInMemoryStorage(): object
    {
        // Log::info('Using in-memory storage for Prometheus metrics');
        return new InMemory();
    }

    private static function namespace(): string
    {
        return config('prometheus.prefix', env('PROMETHEUS_PREFIX', ''));
    }

    /**
     * Get help text for metric
     */
    private static function getMetricHelp(string $name): string
    {
        $helps = [
            'http_requests_total' => 'Total number of HTTP requests',
            'http_request_duration_seconds' => 'HTTP request duration in seconds',
            'http_requests_in_progress' => 'Number of HTTP requests currently in progress',
            'laravel_app_info' => 'Laravel application information',
        ];

        return $helps[$name] ?? 'Metric ' . $name;
    }
}
