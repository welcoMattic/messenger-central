<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;

use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The configuration of a transport.
 */
final class OptionsView
{
    private const int LABEL_WIDTH = 20;

    /**
     * @return list<string>
     */
    public static function render(TransportDefinition $transport): array
    {
        $lines = [
            Format::bold(sprintf('Configuration of %s', Format::safe($transport->name))),
            '',
            self::row('DSN', $transport->maskedDsn() ?? 'custom transport service'),
            self::row('Consumable', $transport->consumable ? 'yes' : 'no'),
            self::row('Failure transport', $transport->failureTransportName ?? 'none'),
            self::row('Used for failures', $transport->failureTransport ? 'yes' : 'no'),
            self::row('Retry strategy', self::retry($transport->retryStrategy)),
            self::row('Serializer', $transport->serializer ?? 'default'),
            '',
            Format::bold('Options'),
        ];

        $options = $transport->maskedOptions();
        if ([] === $options) {
            $lines[] = Format::dim('  No option, the DSN and transport defaults apply.');

            return $lines;
        }

        foreach ($options as $name => $value) {
            $lines[] = self::row('  ' . $name, self::value($value));
        }

        return $lines;
    }

    private static function row(string $label, string $value): string
    {
        return Format::pad(Format::dim(Format::safe($label)), self::LABEL_WIDTH) . ' ' . Format::safe($value);
    }

    /**
     * @param array<string, int|float>|null $strategy
     */
    private static function retry(?array $strategy): string
    {
        if (null === $strategy) {
            return 'custom service';
        }

        $parts = [];
        foreach ($strategy as $name => $value) {
            $parts[] = $name . ' ' . $value;
        }

        return implode(', ', $parts);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value), is_int($value), is_float($value) => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => get_debug_type($value),
        };
    }
}
