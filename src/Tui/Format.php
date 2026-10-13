<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function count;
use function floor;
use function intdiv;
use function max;
use function mb_str_split;
use function min;
use function round;
use function sprintf;
use function str_repeat;

/**
 * Formatting helpers shared by the views.
 */
final class Format
{
    private const array SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const string BARS = ' ▁▂▃▄▅▆▇█';

    public static function bold(string $text): string
    {
        return "\e[1m" . $text . "\e[22m";
    }

    public static function dim(string $text): string
    {
        return "\e[2m" . $text . "\e[22m";
    }

    public static function color(string $text, string $color): string
    {
        $code = match ($color) {
            'red' => 31,
            'green' => 32,
            'yellow' => 33,
            'blue' => 34,
            'magenta' => 35,
            'cyan' => 36,
            'gray' => 90,
            default => 39,
        };

        return "\e[" . $code . 'm' . $text . "\e[39m";
    }

    /**
     * Removes control bytes from text coming from workers or transports.
     */
    public static function safe(string $text): string
    {
        return StringUtils::stripControlBytes($text);
    }

    /**
     * Pads (or cuts) text to an exact visible width.
     */
    public static function pad(string $text, int $width): string
    {
        return AnsiUtils::truncateToWidth($text, max(0, $width), '…', true);
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < (1024 * 1024)) {
            return sprintf('%d KB', intdiv($bytes, 1024));
        }

        return sprintf('%.1f MB', ($bytes / 1024) / 1024);
    }

    public static function duration(?float $milliseconds): string
    {
        return match (true) {
            null === $milliseconds => '',
            $milliseconds < 1000 => sprintf('%.1f ms', $milliseconds),
            default => sprintf('%.2f s', $milliseconds / 1000),
        };
    }

    public static function elapsed(float $seconds): string
    {
        $seconds = (int) floor($seconds);

        return match (true) {
            $seconds < 60 => sprintf('%ds', $seconds),
            $seconds < 3600 => sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }

    public static function spinner(float $now): string
    {
        return self::SPINNER[(int) floor($now * 10) % count(self::SPINNER)];
    }

    /**
     * @param list<int> $values
     */
    public static function sparkline(array $values): string
    {
        $bars = mb_str_split(self::BARS);
        $max = max([1, ...$values]);
        $line = '';
        foreach ($values as $value) {
            $level = 0 === $value ? 0 : max(1, (int) round(($value / $max) * (count($bars) - 1)));
            $line .= $bars[min($level, count($bars) - 1)];
        }

        return $line;
    }

    public static function rule(int $width): string
    {
        return str_repeat('─', max(0, $width));
    }
}
