<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter as OtlpMetricExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('opentelemetry.tracer', function ($app) {
            if (!config('opentelemetry.enabled') || !config('opentelemetry.traces.enabled')) {
                return null;
            }

            try {
                // Create resource with service information
                $resource = ResourceInfoFactory::emptyResource()->merge(
                    ResourceInfo::create(
                        Attributes::create(config('opentelemetry.resource_attributes', []))
                    )
                );

                // Create OTLP exporter
                $transport = (new PsrTransportFactory())->create(
                    config('opentelemetry.traces.exporter.endpoint'),
                    'application/x-protobuf'
                );

                $exporter = new OtlpSpanExporter($transport);

                // Create sampler based on configuration
                $samplerRatio = config('opentelemetry.traces.sampler.ratio', 1.0);
                $sampler = $samplerRatio >= 1.0
                    ? new AlwaysOnSampler()
                    : new ParentBased(new TraceIdRatioBasedSampler($samplerRatio));

                // Create tracer provider - Using SimpleSpanProcessor for immediate export (debugging)
                $tracerProvider = TracerProvider::builder()
                    ->setResource($resource)
                    ->setSampler($sampler)
                    ->addSpanProcessor(
                        new SimpleSpanProcessor($exporter)
                    )
                    ->build();

                // Set global tracer provider
                Globals::registerInitializer(function (Globals $globals) use ($tracerProvider) {
                    return $globals->withTracerProvider($tracerProvider);
                });

                return $tracerProvider->getTracer(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.service_version')
                );
            } catch (\Exception $e) {
                Log::error('Failed to initialize OpenTelemetry tracer: ' . $e->getMessage());
                return null;
            }
        });

        $this->app->singleton(TracerProviderInterface::class, function ($app) {
            return Globals::tracerProvider();
        });

        // Register Meter for metrics
        $this->app->singleton('opentelemetry.meter', function ($app) {
            if (!config('opentelemetry.enabled') || !config('opentelemetry.metrics.enabled')) {
                return null;
            }

            try {
                // Create resource
                $resource = ResourceInfoFactory::emptyResource()->merge(
                    ResourceInfo::create(
                        Attributes::create(config('opentelemetry.resource_attributes', []))
                    )
                );

                // Create OTLP metric exporter
                $transport = (new PsrTransportFactory())->create(
                    config('opentelemetry.metrics.exporter.endpoint'),
                    'application/x-protobuf'
                );

                $exporter = new OtlpMetricExporter($transport);

                // Create metric reader with immediate export (no interval for debugging)
                $reader = new ExportingReader(
                    $exporter,
                    ClockFactory::getDefault()
                );

                // Create meter provider
                $meterProvider = MeterProvider::builder()
                    ->setResource($resource)
                    ->addReader($reader)
                    ->build();

                // Register shutdown function to force metric export
                register_shutdown_function(function () use ($meterProvider) {
                    $meterProvider->shutdown();
                });

                // Set global meter provider
                Globals::registerInitializer(function (Globals $globals) use ($meterProvider) {
                    return $globals->withMeterProvider($meterProvider);
                });

                return $meterProvider->getMeter(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.service_version')
                );
            } catch (\Exception $e) {
                Log::error('Failed to initialize OpenTelemetry meter: ' . $e->getMessage());
                return null;
            }
        });

        $this->app->singleton(MeterProviderInterface::class, function ($app) {
            return Globals::meterProvider();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Initialize tracer and meter on boot
        $this->app->make('opentelemetry.tracer');
        $this->app->make('opentelemetry.meter');
    }
}
