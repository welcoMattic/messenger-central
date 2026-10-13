<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tui\LocalTimezone;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function getenv;
use function putenv;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class LocalTimezoneTest extends TestCase
{
    private string $defaultTimezone;
    private string|false $tz;
    private ?string $link = null;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        $this->tz = getenv('TZ');
        date_default_timezone_set('UTC');
        putenv('TZ');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
        putenv(false === $this->tz ? 'TZ' : 'TZ=' . $this->tz);
        if (null !== $this->link) {
            unlink($this->link);
        }
    }

    public function testItKeepsATimezoneSetForPhp(): void
    {
        date_default_timezone_set('America/Montreal');

        static::assertSame('America/Montreal', LocalTimezone::detect('/nowhere')->getName());
    }

    public function testItUsesTheTimezoneOfTheComputer(): void
    {
        static::assertSame(
            'Asia/Tokyo',
            LocalTimezone::detect($this->link('/usr/share/zoneinfo/Asia/Tokyo'))->getName(),
        );

        putenv('TZ=:Europe/Paris');
        static::assertSame('Europe/Paris', LocalTimezone::detect('/nowhere')->getName(), 'TZ comes first');
    }

    public function testItFallsBackToUtc(): void
    {
        putenv('TZ=Not/A_Timezone');

        static::assertSame('UTC', LocalTimezone::detect($this->link('/nowhere'))->getName());
    }

    private function link(string $target): string
    {
        $path = tempnam(sys_get_temp_dir(), 'localtime');
        static::assertNotFalse($path);
        unlink($path);
        symlink($target, $path);

        return $this->link = $path;
    }
}
