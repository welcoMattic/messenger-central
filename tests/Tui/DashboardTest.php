<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Tests\Worker\FakeProcessFactory;
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Tui\Dashboard;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function microtime;
use function usleep;

#[RequiresPhpExtension('pcntl')]
final class DashboardTest extends TestCase
{
    private const int COLUMNS = 140;
    private const int ROWS = 30;

    private VirtualTerminal $terminal;
    private ScreenBuffer $screen;
    private WorkerSupervisor $supervisor;
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->terminal = new VirtualTerminal(self::COLUMNS, self::ROWS);
        $this->screen = new ScreenBuffer(self::COLUMNS, self::ROWS);
        $this->supervisor = new WorkerSupervisor(new FakeProcessFactory(messages: 3));
        $this->dashboard = new Dashboard(
            new TransportCatalog([
                'async' => [
                    'dsn' => 'doctrine://default?queue_name=async',
                    // @mago-expect lint:no-literal-password
                    'options' => ['auto_setup' => false, 'password' => 'secret'],
                    'failure_transport_name' => 'failed',
                    'retry_strategy' => ['max_retries' => 3, 'delay' => 1000],
                    'message_classes' => [RoutedByClass::class],
                ],
                'failed' => ['dsn' => 'doctrine://default?queue_name=failed', 'failure_transport' => true],
                'sync' => ['dsn' => 'sync://', 'consumable' => false],
            ]),
            $this->supervisor,
            new QueueInspector(new ServiceLocator(['async' => static fn(): FakeReceiver => new FakeReceiver()])),
            $this->terminal,
        );
        $this->dashboard->start();
    }

    protected function tearDown(): void
    {
        $this->dashboard->stop();
        $this->supervisor->stopAll(1.0);
    }

    public function testItListsTheTransportsAndShowsTheFirstOne(): void
    {
        // When
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Messenger Central', $screen);
        static::assertStringContainsString('3 transports', $screen);
        static::assertStringContainsString('▸ async (42)', $screen);
        static::assertStringContainsString('▸ failed', $screen);
        static::assertStringContainsString('▸ sync', $screen);
        static::assertStringContainsString('async  ○ stopped', $screen);
        static::assertStringContainsString('No worker. Press s to start 1 worker', $screen);
        static::assertStringContainsString('Queue  42 waiting', $screen);
        static::assertStringContainsString(RoutedByClass::class . ' #41', $screen);
        static::assertStringContainsString('q quit', $screen);
    }

    public function testItStartsAndScalesWorkers(): void
    {
        // Given
        $this->dashboard->input('+');
        static::assertSame(2, $this->dashboard->scale('async'));

        // When
        $this->dashboard->input('s');
        $screen = $this->waitFor('✓ 4 processed');

        // Then
        static::assertStringContainsString('2 workers running', $screen);
        static::assertStringContainsString('▸ async ●2', $screen);
        static::assertStringContainsString('✗ 2 failed', $screen);
        static::assertStringContainsString('RuntimeException: boom', $screen);
        static::assertStringContainsString('memory 4.0 MB, worker peak 4.0 MB', $screen);

        // When
        $this->dashboard->input('-');

        // Then
        static::assertCount(1, $this->supervisor->active('async'));

        // When
        $this->dashboard->input('s');

        // Then
        static::assertSame([], $this->supervisor->active('async'));
    }

    public function testItOpensTheActionsOfATransport(): void
    {
        // When
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('▾ async', $screen);
        static::assertStringContainsString('├ Message classes (1)', $screen);
        static::assertStringContainsString('├ Start workers (×1)', $screen);
        static::assertStringContainsString('└ Options', $screen);

        // When
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Message classes routed to async', $screen);
        static::assertStringContainsString('· ' . RoutedByClass::class, $screen);

        // When
        $this->dashboard->input('o');
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Configuration of async', $screen);
        static::assertStringContainsString('doctrine://default?queue_name=async', $screen);
        static::assertStringContainsString('max_retries 3, delay 1000', $screen);
        static::assertStringContainsString('password', $screen);
        static::assertStringNotContainsString('secret', $screen);
    }

    public function testItFollowsTheCursor(): void
    {
        // When
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\e[B");
        $screen = $this->tick();

        // Then
        static::assertSame('sync', $this->dashboard->selected());
        static::assertStringContainsString('This transport cannot be consumed.', $screen);

        // When
        $this->dashboard->input('s');
        $screen = $this->tick();

        // Then
        static::assertFalse($this->supervisor->hasRunning());
        static::assertStringContainsString('"sync" cannot be consumed.', $screen);
    }

    private function tick(): string
    {
        $this->dashboard->tick();
        $this->dashboard->render();
        $this->screen->write($this->terminal->consumeOutput());

        return $this->screen->getScreen();
    }

    private function waitFor(string $text, float $timeout = 5.0): string
    {
        $deadline = microtime(true) + $timeout;
        do {
            $screen = $this->tick();
            if (str_contains($screen, $text)) {
                return $screen;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        static::fail('"' . $text . '" never showed up:' . "\n" . $screen);
    }
}
