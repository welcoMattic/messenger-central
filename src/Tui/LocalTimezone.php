<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui;

use DateTimeZone;
use Exception;

use function date_default_timezone_get;
use function getenv;
use function is_link;
use function ltrim;
use function readlink;
use function strlen;
use function strpos;
use function substr;

/**
 * The timezone to show times in.
 *
 * PHP falls back to UTC when php.ini sets no timezone, which is the case of
 * most CLI setups: the timezone of the computer is then a better guess.
 */
final class LocalTimezone
{
    public static function detect(string $localtime = '/etc/localtime'): DateTimeZone
    {
        $default = date_default_timezone_get();
        if ('UTC' !== $default) {
            // Set in php.ini or by the application
            return new DateTimeZone($default);
        }

        $environment = getenv('TZ');
        foreach ([false === $environment ? null : ltrim($environment, ':'), self::fromLink($localtime)] as $name) {
            if (null === $name || '' === $name) {
                continue;
            }

            try {
                return new DateTimeZone($name);
            } catch (Exception) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    /**
     * @return string|null "Europe/Paris" for a link to ".../zoneinfo/Europe/Paris"
     */
    private static function fromLink(string $path): ?string
    {
        $target = is_link($path) ? readlink($path) : false;
        if (false === $target) {
            return null;
        }

        $position = strpos($target, 'zoneinfo/');

        return false === $position ? null : substr($target, $position + strlen('zoneinfo/'));
    }
}
