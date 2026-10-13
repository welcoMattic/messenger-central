<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use DateTimeZone;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Tests\Failure\ListableTransport;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Tests\Worker\FakeProcessFactory;
use Welcomattic\MessengerCentral\Transport\InProcessQueueProbe;
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
    private FakeProcessFactory $factory;
    private WorkerSupervisor $supervisor;
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->factory = new FakeProcessFactory(messages: 3);
        $this->supervisor = new WorkerSupervisor($this->factory);
        $this->dashboard = $this->dashboard();
    }

    private function dashboard(
        bool $parallel = true,
        ?TransportCatalog $catalog = null,
        ?QueueInspector $inspector = null,
        ClockInterface $clock = new NativeClock(),
        ?FailedMessages $failedMessages = null,
    ): Dashboard {
        $this->terminal = new VirtualTerminal(self::COLUMNS, self::ROWS);
        $this->screen = new ScreenBuffer(self::COLUMNS, self::ROWS);
        $dashboard = new Dashboard(
            $catalog ?? new TransportCatalog([
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
                'name:with:colons' => ['dsn' => 'in-memory://'],
            ]),
            $this->supervisor,
            new InProcessQueueProbe(
                $inspector ?? new QueueInspector(new ServiceLocator([
                    'async' => static fn(): FakeReceiver => new FakeReceiver(),
                ])),
            ),
            $failedMessages ?? new FailedMessages(new ServiceLocator([])),
            $this->terminal,
            $clock,
            parallel: $parallel,
            timezone: new DateTimeZone('UTC'),
        );
        $dashboard->start();

        return $dashboard;
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
        static::assertStringContainsString('4 transports', $screen);
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

    public function testItStopsWorkersThatWaitToStartAgain(): void
    {
        // Given: a worker that crashes, started again after a delay
        $this->factory->mode = 'crash';
        $this->dashboard->input('s');
        $this->waitFor('a worker starts again in');

        // When
        $this->dashboard->input('s');
        $screen = $this->tick();

        // Then
        static::assertSame(0, $this->supervisor->desired('async'));
        static::assertNull($this->supervisor->nextRestart('async'));
        static::assertStringContainsString('Stopping the workers of "async".', $screen);
    }

    public function testItDoesNotStartWorkersOnAFailureTransport(): void
    {
        // Given
        $this->dashboard->input("\e[B");

        // When
        $this->dashboard->input('s');
        $this->dashboard->input('+');
        $this->dashboard->input('c');
        $screen = $this->tick();

        // Then
        static::assertSame('failed', $this->dashboard->selected());
        static::assertFalse($this->supervisor->hasRunning());
        static::assertSame(1, $this->dashboard->scale('failed'));
        static::assertStringContainsString(
            '"failed" is a failure transport: open it to send its messages back.',
            $screen,
        );
        static::assertStringContainsString('failed  [failure transport]', $screen);
        static::assertStringContainsString('Workers do not consume a failure transport.', $screen);

        // When
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('▾ failed', $screen);
        static::assertStringNotContainsString('Start workers', $screen);
        static::assertStringNotContainsString('Concurrency (', $screen);
    }

    public function testItEditsAFailedMessageAndSendsItBack(): void
    {
        // Given
        $this->dashboard->stop();
        $async = new ListableTransport();
        $failed = new ListableTransport();
        $failed->send(new Envelope(new RoutedByClass('bad payload'), [
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(RuntimeException::class, 0, 'Invalid payload'),
        ]));
        $transports = new ServiceLocator([
            'async' => static fn(): ListableTransport => $async,
            'failed' => static fn(): ListableTransport => $failed,
        ]);
        $this->dashboard = $this->dashboard(
            inspector: new QueueInspector($transports),
            failedMessages: new FailedMessages($transports),
        );

        // When
        $this->dashboard->input("\e[B");
        $this->tick();
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Failed messages  1', $screen);
        static::assertStringContainsString('RoutedByClass #1 from async  RuntimeException: Invalid payload', $screen);
        static::assertStringContainsString('├ #1 RoutedByClass', $screen);

        // When
        $this->dashboard->input("\e[B");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Failed on async', $screen);
        static::assertStringContainsString('⏎ edit it and send it back to async', $screen);
        static::assertStringContainsString('"payload": "bad payload"', $screen);

        // When: an invalid document, as the cursor starts at the beginning
        $this->dashboard->input("\r");
        $this->dashboard->input('x');
        $this->dashboard->input("\x13");
        $screen = $this->tick();

        // Then
        static::assertTrue($this->dashboard->isEditing());
        static::assertStringContainsString('Editing RoutedByClass #1', $screen);
        static::assertStringContainsString('The message is not valid JSON: Syntax error.', $screen);
        static::assertCount(1, $failed->envelopes);

        // When: the payload is fixed, on the line below the cursor
        $this->dashboard->input("\x7f");
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\x05");
        // Erases 'bad payload"' at the end of the line
        for ($erased = 0; $erased < 12; ++$erased) {
            $this->dashboard->input("\x7f");
        }

        $this->dashboard->input('good payload"');
        $this->dashboard->input("\x13");
        $screen = $this->tick();

        // Then
        static::assertFalse($this->dashboard->isEditing());
        static::assertSame([], $failed->envelopes);
        static::assertEquals(new RoutedByClass('good payload'), $async->envelopes['1']->getMessage());
        static::assertStringContainsString('Sent RoutedByClass #1 back to "async".', $screen);

        // When
        $screen = $this->tick();

        // Then
        static::assertSame('failed', $this->dashboard->selected());
        static::assertStringContainsString('Failed messages  none', $screen);
        static::assertStringNotContainsString('├ #1 RoutedByClass', $screen);
    }

    public function testItSendsAFailedMessageBackAsIs(): void
    {
        // Given
        $this->dashboard->stop();
        $async = new ListableTransport();
        $failed = new ListableTransport();
        $message = new RoutedByClass('payload');
        $failed->send(new Envelope($message, [new SentToFailureTransportStamp('async')]));
        $transports = new ServiceLocator([
            'async' => static fn(): ListableTransport => $async,
            'failed' => static fn(): ListableTransport => $failed,
        ]);
        $this->dashboard = $this->dashboard(
            inspector: new QueueInspector($transports),
            failedMessages: new FailedMessages($transports),
        );
        $this->dashboard->input("\e[B");
        $this->tick();
        $this->dashboard->input("\r");
        $this->tick();

        // When: on the transport, not on a message
        $this->dashboard->input('r');

        // Then
        static::assertCount(1, $failed->envelopes);

        // When
        $this->dashboard->input("\e[B");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('⏎ edit it and send it back to async   r send it back as is', $screen);
        static::assertStringContainsString('r send back as is', $screen);

        // When
        $this->dashboard->input('r');
        $screen = $this->tick();

        // Then
        static::assertFalse($this->dashboard->isEditing());
        static::assertSame([], $failed->envelopes);
        static::assertSame($message, $async->envelopes['1']->getMessage());
        static::assertStringContainsString('Sent RoutedByClass #1 back to "async".', $screen);
        static::assertSame('failed', $this->dashboard->selected());
    }

    public function testItCancelsTheEditionOfAFailedMessage(): void
    {
        // Given
        $this->dashboard->stop();
        $failed = new ListableTransport();
        $failed->send(new Envelope(new RoutedByClass('bad payload'), [new SentToFailureTransportStamp('async')]));
        $transports = new ServiceLocator(['failed' => static fn(): ListableTransport => $failed]);
        $this->dashboard = $this->dashboard(
            inspector: new QueueInspector($transports),
            failedMessages: new FailedMessages($transports),
        );
        $this->dashboard->input("\e[B");
        $this->tick();
        $this->dashboard->input("\r");
        $this->tick();
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\r");
        static::assertTrue($this->dashboard->isEditing());

        // When
        $this->dashboard->input('q');
        $this->dashboard->input("\e");
        $screen = $this->tick();

        // Then
        static::assertFalse($this->dashboard->isEditing());
        static::assertCount(1, $failed->envelopes);
        static::assertStringContainsString('"payload": "bad payload"', $screen);
    }

    public function testItAsksForTheConcurrency(): void
    {
        // When
        $this->dashboard->input('c');
        $this->dashboard->input('1');
        $this->dashboard->input('2');
        $this->dashboard->input("\x7f");
        $this->dashboard->input('6');
        $this->dashboard->input('s');
        $this->dashboard->input("\e[B");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Messages handled at a time by each worker of async (1-32): 16', $screen);
        static::assertFalse($this->supervisor->hasRunning(), 'Keys go to the prompt');
        static::assertSame('async', $this->dashboard->selected());

        // When
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertSame(16, $this->dashboard->concurrency('async'));
        static::assertStringContainsString('Workers of "async" will handle 16 messages at a time.', $screen);

        // When
        $this->dashboard->input('s');
        $screen = $this->waitFor('up to 16 messages at a time');

        // Then
        static::assertSame([['async', 1, 16]], $this->factory->created);
        static::assertStringContainsString('Workers  16 messages at a time each', $screen);
        static::assertStringContainsString('(child processes excluded)', $screen);

        // When
        $this->dashboard->input('+');

        // Then
        static::assertCount(2, $this->supervisor->active('async'));

        // When
        $this->dashboard->input('+');

        // Then
        static::assertCount(2, $this->supervisor->active('async'), 'At most 32 messages at a time');
    }

    public function testItRestartsTheWorkersWithTheNewConcurrency(): void
    {
        // Given
        $this->dashboard->input('s');
        $this->waitFor('✓ 2 processed');

        // When
        $this->dashboard->input('c');
        $this->dashboard->input('4');
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('Restarting the workers of "async" with a concurrency of 4.', $screen);
        static::assertSame([['async', 1, 1], ['async', 2, 4]], $this->factory->created);
    }

    public function testItRefusesAConcurrencyOutOfBounds(): void
    {
        // When
        $this->dashboard->input('c');
        $this->dashboard->input('9');
        $this->dashboard->input('9');
        $this->dashboard->input("\r");
        $screen = $this->tick();

        // Then
        static::assertSame(1, $this->dashboard->concurrency('async'));
        static::assertStringContainsString('The concurrency of "async" must be between 1 and 32.', $screen);

        // When
        $this->dashboard->input('c');
        $this->dashboard->input('3');
        $this->dashboard->input("\e");

        // Then
        static::assertSame(1, $this->dashboard->concurrency('async'), 'Escape cancels');
    }

    public function testItExplainsWhatConcurrencyNeeds(): void
    {
        // Given
        $this->dashboard->stop();
        $this->dashboard = $this->dashboard(parallel: false);

        // When
        $this->dashboard->input('c');
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('several messages at a time needs Symfony 8.2 and amphp/parallel', $screen);
        static::assertStringNotContainsString('c concurrency', $screen);

        // When
        $this->dashboard->input('o');
        $screen = $this->tick();

        // Then
        static::assertStringContainsString('1 message at a time, more needs Symfony 8.2 and amphp/parallel', $screen);
    }

    public function testItShowsTheMessagesHandledAtTheSameTime(): void
    {
        // Given
        $this->factory->mode = 'interleaved';
        $this->dashboard->input('c');
        $this->dashboard->input('3');
        $this->dashboard->input("\r");

        // When
        $this->dashboard->input('s');
        $screen = $this->waitFor('3/3 busy');

        // Then
        static::assertStringContainsString('3/3 busy  App\Message\Ping #1', $screen, 'The oldest message');
    }

    public function testItShowsWhyAWorkerCrashed(): void
    {
        // Given
        $this->factory->mode = 'crash';

        // When
        $this->dashboard->input('s');
        $screen = $this->waitFor('exited with code 1');

        // Then
        static::assertStringContainsString('Connection refused (Connection.php line 42)', $screen);
    }

    public function testItHandlesTransportNamesWithColons(): void
    {
        // When
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\r");
        $this->dashboard->input("\e[B");
        $this->dashboard->input("\e[B");
        $screen = $this->tick();

        // Then
        static::assertSame('name:with:colons', $this->dashboard->selected());
        static::assertStringContainsString('▾ name:with:colons', $screen);

        // When
        $this->dashboard->input("\r");

        // Then
        static::assertCount(1, $this->supervisor->active('name:with:colons'));
    }

    public function testItInspectsAFailingTransportLessAndLessOften(): void
    {
        // Given
        $this->dashboard->stop();
        $receiver = new FailingReceiver();
        $clock = new MockClock();
        $this->dashboard = $this->dashboard(
            catalog: new TransportCatalog(['broken' => ['dsn' => 'redis://localhost']]),
            inspector: new QueueInspector(new ServiceLocator(['broken' => static fn(): FailingReceiver => $receiver])),
            clock: $clock,
        );

        // When
        $calls = [];
        foreach ([0, 1, 1.5, 1, 1, 1, 1] as $seconds) {
            $clock->sleep($seconds);
            $this->dashboard->tick();
            $calls[] = $receiver->calls;
        }

        // Then
        static::assertSame([1, 1, 2, 2, 2, 2, 3], $calls, 'After 2, then 4 seconds');
        static::assertStringContainsString('Connection timed out', $this->tick());
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
