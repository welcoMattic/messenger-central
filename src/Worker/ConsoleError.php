<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use function array_slice;
use function count;
use function implode;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function trim;

/**
 * Finds the error in the output of a console command that failed.
 */
final class ConsoleError
{
    // "In Connection.php line 42:", the header of an exception rendered by the console
    private const string HEADER = '/^In .+ line \d+:$/';

    // "[RuntimeException]", shown in verbose mode
    private const string CLASS_LINE = '/^\[[^\]\s]+\]$/';

    /**
     * @param list<string> $lines the output, most recent line last
     *
     * @return string|null the message of the last exception (the root cause), or else the last
     *                     PHP error, or else the last line
     */
    public static function summarize(array $lines): ?string
    {
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            if (1 === preg_match(self::HEADER, $line)) {
                return self::message(array_slice($lines, $i + 1), $line);
            }
        }

        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            if (1 === preg_match('/^(PHP )?(Fatal error|Parse error|Warning|Error):/', $line)) {
                return self::collapse($line);
            }
        }

        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            // The synopsis the console prints after an error
            if ('' !== $line && !str_starts_with($line, 'messenger:consume ')) {
                return self::collapse($line);
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines the lines after the header
     */
    private static function message(array $lines, string $header): string
    {
        $message = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (1 === preg_match(self::CLASS_LINE, $line)) {
                continue;
            }

            if (
                1 === preg_match(self::HEADER, $line)
                || str_starts_with($line, 'messenger:consume ')
                || str_starts_with($line, 'Exception trace:')
            ) {
                break;
            }

            $message[] = $line;
        }

        $where = (string) preg_replace('/^In (.+):$/', '$1', $header);

        return [] === $message ? self::collapse($header) : self::collapse(implode(' ', $message) . ' (' . $where . ')');
    }

    private static function collapse(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }
}
