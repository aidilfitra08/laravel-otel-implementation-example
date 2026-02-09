<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeImmutable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Logs\PsrSeverityMapperInterface;
use Throwable;

class OpenTelemetryLogHandler extends AbstractProcessingHandler
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerProviderInterface $loggerProvider,
        ?string $loggerName = null,
        ?string $serviceVersion = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $loggerName = $loggerName
            ?: config('opentelemetry.service_name', config('app.name', 'laravel-app'));
        $serviceVersion = $serviceVersion ?: config('opentelemetry.service_version');

        $this->logger = $loggerProvider->getLogger($loggerName, $serviceVersion);
    }

    protected function write(LogRecord $record): void
    {
        if (!config('opentelemetry.enabled') || !config('opentelemetry.logs.enabled')) {
            return;
        }

        $psrLevel = $record->level->toPsrLogLevel();
        $severity = Severity::fromPsr3($psrLevel);

        $attributes = $this->buildAttributes($record);

        $builder = $this->logger->logRecordBuilder()
            ->setTimestamp($this->toNanoseconds($record->datetime))
            ->setObservedTimestamp($this->toNanoseconds($record->datetime))
            ->setSeverityNumber($severity)
            ->setSeverityText($record->level->getName())
            ->setBody($record->message)
            ->setEventName($record->channel)
            ->setContext(Context::getCurrent())
            ->setAttributes($attributes);

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $builder->setException($exception);
        }

        $builder->emit();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttributes(LogRecord $record): array
    {
        $attributes = [
            'log.logger' => $record->channel,
            'log.channel' => $record->channel,
            'log.level' => $record->level->getName(),
            'log.severity_number' => PsrSeverityMapperInterface::SEVERITY_NUMBER[$record->level->toPsrLogLevel()] ?? null,
        ];

        $attributes = array_merge($attributes, $this->normalizeAttributes($record->context));
        $attributes = array_merge($attributes, $this->normalizeAttributes($record->extra));

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if ($value instanceof Throwable) {
                continue;
            }

            $normalized[$key] = $this->normalizeAttributeValue($value);
        }

        return $normalized;
    }

    private function normalizeAttributeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeAttributeValue($item);
            }

            return $normalized;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        return json_encode($value);
    }

    private function toNanoseconds(DateTimeImmutable $dateTime): int
    {
        $seconds = (int) $dateTime->format('U');
        $micros = (int) $dateTime->format('u');

        return ($seconds * 1000000000) + ($micros * 1000);
    }
}
