<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use JsonException;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function strpos;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Wire format between a worker and the dashboard: one JSON object per
 * line, behind a marker so that the rest of the worker output is kept apart.
 */
final class WorkerReport
{
    public const string MARKER = '@messenger-central ';

    public const string STARTED = 'started';
    public const string RECEIVED = 'received';
    public const string HANDLED = 'handled';
    public const string FAILED = 'failed';
    public const string HEARTBEAT = 'heartbeat';
    public const string STOPPED = 'stopped';

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(string $type, array $payload = []): string
    {
        return (
            self::MARKER
            . json_encode(
                ['type' => $type] + $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PRESERVE_ZERO_FRACTION,
            )
            . "\n"
        );
    }

    /**
     * @return array<string, mixed>|null null when the line holds no report
     */
    public static function decode(string $line): ?array
    {
        $position = strpos($line, self::MARKER);
        if (false === $position) {
            return null;
        }

        try {
            $report = json_decode(substr($line, $position + strlen(self::MARKER)), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($report) || !is_string($report['type'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $report */
        return $report;
    }
}
