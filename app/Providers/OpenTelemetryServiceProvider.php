<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;

use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

// ---- Traces ----
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Contrib\Otlp\SpanExporter;

// ---- Logs ----
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\Contrib\Otlp\LogsExporter;

// ---- Metrics (kept, updated, commented) ----
// use OpenTelemetry\SDK\Metrics\MeterProvider;
// use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
// use OpenTelemetry\Contrib\Otlp\MetricExporter;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!config('opentelemetry.enabled')) {
            return;
        }

        try {
            $tracesEnabled = (bool) config('opentelemetry.traces.enabled');
            $logsEnabled = (bool) config('opentelemetry.logs.enabled');

            /**
             * -------- Resource --------
             */
            $resource = ResourceInfoFactory::emptyResource()->merge(
                ResourceInfo::create(
                    Attributes::create(
                        config('opentelemetry.resource_attributes', [])
                    )
                )
            );

            /**
             * =========================
             * ======== TRACES =========
             * =========================
             */
            $tracerProvider = null;

            if ($tracesEnabled) {
                $spanTransport = (new PsrTransportFactory())->create(
                    config('opentelemetry.traces.exporter.endpoint'),
                    'application/x-protobuf'
                );

                $spanExporter = new SpanExporter($spanTransport);

                $spanProcessor = new BatchSpanProcessor($spanExporter, Clock::getDefault());

                $samplerRatio = config('opentelemetry.traces.sampler.ratio', 1.0);
                $sampler = $samplerRatio >= 1.0
                    ? new AlwaysOnSampler()
                    : new ParentBased(
                        new TraceIdRatioBasedSampler($samplerRatio)
                    );

                $tracerProvider = TracerProvider::builder()
                    ->setResource($resource)
                    ->setSampler($sampler)
                    ->addSpanProcessor($spanProcessor)
                    ->build();
            }

            /**
             * =========================
             * ========= LOGS ==========
             * =========================
             */
            $loggerProvider = null;

            if ($logsEnabled) {
                $logTransport = (new PsrTransportFactory())->create(
                    config('opentelemetry.logs.exporter.endpoint'),
                    'application/x-protobuf'
                );

                $logExporter = new LogsExporter($logTransport);

                $logProcessor = new BatchLogRecordProcessor($logExporter, Clock::getDefault());

                $loggerProvider = LoggerProvider::builder()
                    ->setResource($resource)
                    ->addLogRecordProcessor($logProcessor)
                    ->build();
            }

            /**
             * =========================
             * ======= METRICS =========
             * (kept, updated, not used)
             * =========================
             */
            /*
            $meterProvider = null;

            if ($metricsEnabled) {
                $metricTransport = (new PsrTransportFactory())->create(
                    config('opentelemetry.metrics.exporter.endpoint'),
                    'application/x-protobuf'
                );

                $metricExporter = new MetricExporter($metricTransport);

                $metricReader = new ExportingReader(
                    $metricExporter,
                    Clock::getDefault()
                );

                $meterProvider = MeterProvider::builder()
                    ->setResource($resource)
                    ->addReader($metricReader)
                    ->build();
            }
            */

            /**
             * =========================
             * === REGISTER SDK ONCE ===
             * =========================
             */
            $sdkBuilder = Sdk::builder();

            if ($tracerProvider) {
                $sdkBuilder->setTracerProvider($tracerProvider);
            }

            if ($loggerProvider) {
                $sdkBuilder->setLoggerProvider($loggerProvider);
            }

            // if ($meterProvider) {
            //     $sdkBuilder->setMeterProvider($meterProvider); // not used yet
            // }

            $sdkBuilder->buildAndRegisterGlobal();

            /**
             * ===== Container bindings =====
             */
            $this->app->singleton(
                TracerProviderInterface::class,
                fn() => Globals::tracerProvider()
            );

            $this->app->singleton(
                'opentelemetry.tracer',
                fn() => $tracesEnabled
                    ? Globals::tracerProvider()->getTracer(
                        config('opentelemetry.service_name'),
                        config('opentelemetry.service_version')
                    )
                    : null
            );

            $this->app->singleton(
                LoggerProviderInterface::class,
                fn() => Globals::loggerProvider()
            );

            $this->app->singleton(
                'opentelemetry.logger',
                fn() => $logsEnabled
                    ? Globals::loggerProvider()->getLogger(
                        config('opentelemetry.service_name'),
                        config('opentelemetry.service_version')
                    )
                    : null
            );

            /*
            $this->app->singleton(
                MeterProviderInterface::class,
                fn () => Globals::meterProvider()
            );
            */

            /**
             * ===== Graceful shutdown =====
             */
            register_shutdown_function(function () use (
                $tracerProvider,
                $loggerProvider,
            ) {
                if ($loggerProvider) {
                    $loggerProvider->shutdown();
                }

                if ($tracerProvider) {
                    $tracerProvider->shutdown();
                }

                // if ($meterProvider) {
                //     $meterProvider->shutdown();
                // }
            });
        } catch (\Throwable $e) {
            Log::error('[OpenTelemetry] bootstrap failed: ' . $e->getMessage());
        }
    }

    public function boot(): void
    {
        if (!config('opentelemetry.enabled')) {
            return;
        }

        // Force initialization
        $this->app->make(TracerProviderInterface::class);
        $this->app->make(LoggerProviderInterface::class);
        // $this->app->make(MeterProviderInterface::class);
    }
}
