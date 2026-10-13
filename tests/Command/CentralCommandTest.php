<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Welcomattic\MessengerCentral\Command\CentralCommand;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Tests\Worker\FakeProcessFactory;
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

final class CentralCommandTest extends TestCase
{
    public function testItRefusesToRunWithoutDebug(): void
    {
        // Given
        $tester = self::tester(debug: false);

        // When
        $status = $tester->execute([]);

        // Then
        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('Messenger Central is a development tool', $tester->getDisplay());
    }

    public function testItRunsWithoutDebugWhenForced(): void
    {
        // Given
        $tester = self::tester(debug: false);

        // When
        $tester->execute(['--force' => true]);

        // Then: past the guard, a test has no terminal
        static::assertStringContainsString('needs an interactive terminal', $tester->getDisplay());
    }

    private static function tester(bool $debug): CommandTester
    {
        $command = new Command(
            null,
            new CentralCommand(
                new TransportCatalog(['async' => []]),
                new WorkerSupervisor(new FakeProcessFactory()),
                new QueueInspector(new ServiceLocator([])),
                new FailedMessages(new ServiceLocator([])),
                debug: $debug,
            ),
        );

        return new CommandTester($command);
    }
}
