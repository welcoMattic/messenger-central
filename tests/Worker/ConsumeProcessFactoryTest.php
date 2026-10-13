<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Worker\ConsumeProcessFactory;
use Welcomattic\MessengerCentral\Worker\WorkerReporter;

use function getmypid;
use function sprintf;

final class ConsumeProcessFactoryTest extends TestCase
{
    public function testItConsumesTheTransportThroughTheConsole(): void
    {
        // Given
        $factory = new ConsumeProcessFactory('/app/bin/console', 'dev', ['--time-limit=3600'], '/usr/bin/php');

        // When
        $process = $factory->create('async', 7);

        // Then
        static::assertSame(
            "'/usr/bin/php' '/app/bin/console' 'messenger:consume' 'async' '--no-interaction' '--time-limit=3600'",
            $process->getCommandLine(),
        );
        static::assertNull($process->getTimeout());
        static::assertSame(
            [
                WorkerReporter::ENV_VAR => '7',
                'MESSENGER_CONSUMER_NAME' => sprintf('messenger-central-%d-7', getmypid()),
                'APP_ENV' => 'dev',
            ],
            $process->getEnv(),
        );
    }

    public function testItHandlesSeveralMessagesAtATime(): void
    {
        // Given
        $factory = new ConsumeProcessFactory('bin/console', 'dev', phpBinary: 'php');

        // When
        $process = $factory->create('async', 1, 4);

        // Then
        static::assertStringEndsWith("'--no-interaction' '--concurrency=4'", $process->getCommandLine());
    }
}
