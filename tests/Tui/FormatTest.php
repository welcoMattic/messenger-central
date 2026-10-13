<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tui\Format;

final class FormatTest extends TestCase
{
    public function testItFormatsNumbers(): void
    {
        static::assertSame('512 KB', Format::bytes(512 * 1024));
        static::assertSame('1.5 MB', Format::bytes(3 * 512 * 1024));
        static::assertSame('', Format::duration(null));
        static::assertSame('12.3 ms', Format::duration(12.34));
        static::assertSame('1.50 s', Format::duration(1500.0));
        static::assertSame('59s', Format::elapsed(59.9));
        static::assertSame('2m05s', Format::elapsed(125.0));
        static::assertSame('1h01m', Format::elapsed(3660.0));
    }

    public function testItFormatsTimesInTheGivenTimezone(): void
    {
        static::assertSame('14:00:00', Format::time(1_791_892_800.5, new DateTimeZone('Europe/Paris')));
        static::assertSame('12:00:00', Format::time(1_791_892_800.5, new DateTimeZone('UTC')));
    }

    public function testItDrawsSparklines(): void
    {
        static::assertSame('', Format::sparkline([]));
        static::assertSame('   ', Format::sparkline([0, 0, 0]));
        static::assertSame(' ▁█', Format::sparkline([0, 1, 100]), 'A small value stays visible');
        static::assertSame('▄█', Format::sparkline([1, 2]));
    }
}
