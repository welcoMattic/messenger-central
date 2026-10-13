<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Worker\WorkerReport;
use Welcomattic\MessengerCentral\Worker\WorkerReporter;

use function array_column;
use function array_filter;
use function array_values;
use function explode;
use function fopen;
use function rewind;
use function stream_get_contents;
use function trim;

final class WorkerReporterTest extends TestCase
{
    private const float HANDLING_SECONDS = 0.25;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-13 12:00:00.5 UTC');
    }

    public function testItReportsWhatTheWorkerDoes(): void
    {
        // Given
        $stream = $this->stream();
        $this->runWorker(new WorkerReporter('7', $stream, heartbeatIntervalMs: 0, clock: $this->clock));

        // When
        $reports = $this->reports($stream);

        // Then
        static::assertSame(
            [
                WorkerReport::STARTED,
                WorkerReport::RECEIVED,
                WorkerReport::HANDLED,
                WorkerReport::RECEIVED,
                WorkerReport::FAILED,
                WorkerReport::STOPPED,
            ],
            self::types($reports),
        );

        static::assertSame('7', $reports[0]['worker']);

        $received = $this->first($reports, WorkerReport::RECEIVED);
        static::assertSame(1, $received['seq']);
        static::assertSame(1_791_892_800.5, $received['at']);

        $handled = $this->first($reports, WorkerReport::HANDLED);
        static::assertSame('async', $handled['transport']);
        static::assertSame(RoutedByClass::class, $handled['class']);
        static::assertSame('1', $handled['id']);
        static::assertSame(1, $handled['seq']);
        static::assertSame(250.0, $handled['duration_ms'] ?? null);
        static::assertIsInt($handled['memory'] ?? null);

        $failed = $this->first($reports, WorkerReport::FAILED);
        static::assertSame(2, $failed['seq']);
        static::assertFalse($failed['will_retry']);
        static::assertSame('RuntimeException: boom on line one boom on line two', $failed['error']);
        static::assertSame(250.0, $failed['duration_ms'] ?? null);

        $heartbeat = $this->first($reports, WorkerReport::HEARTBEAT);
        static::assertSame(0, $heartbeat['in_flight']);
    }

    public function testItTracksMessagesHandledAtTheSameTime(): void
    {
        // Given
        $stream = $this->stream();
        $this->runWorker(new WorkerReporter('7', $stream, heartbeatIntervalMs: 0, clock: $this->clock), concurrency: 2);

        // When
        $reports = $this->reports($stream);

        // Then
        static::assertSame(
            [
                WorkerReport::STARTED,
                WorkerReport::RECEIVED,
                WorkerReport::RECEIVED,
                WorkerReport::FAILED,
                WorkerReport::HANDLED,
                WorkerReport::STOPPED,
            ],
            self::types($reports),
            'The second message is handled first',
        );

        $failed = $this->first($reports, WorkerReport::FAILED);
        static::assertSame(2, $failed['seq']);
        static::assertSame(250.0, $failed['duration_ms'] ?? null);

        $handled = $this->first($reports, WorkerReport::HANDLED);
        static::assertSame(1, $handled['seq']);
        static::assertSame(500.0, $handled['duration_ms'] ?? null, 'It waited for the second message');
    }

    public function testItSendsAHeartbeatAtMostEveryInterval(): void
    {
        // Given
        $stream = $this->stream();

        // When
        $this->runWorker(new WorkerReporter('7', $stream, heartbeatIntervalMs: 400, clock: $this->clock));

        // Then
        $heartbeats = array_filter(
            array_column($this->reports($stream), 'type'),
            static fn(mixed $type): bool => WorkerReport::HEARTBEAT === $type,
        );
        static::assertCount(1, $heartbeats, 'The second message is handled 250 ms after the first heartbeat');
    }

    public function testItStopsTheWorkerWhenTheDashboardIsGone(): void
    {
        // Given
        $stream = fopen('php://memory', 'r');
        static::assertNotFalse($stream, 'A stream that cannot be written to, like a broken pipe');

        // When
        $transport = $this->runWorker(new WorkerReporter('7', $stream, heartbeatIntervalMs: 0, clock: $this->clock));

        // Then
        static::assertCount(1, $transport->getAcknowledged(), 'The worker stopped after its current message');
    }

    public function testItStaysSilentOutsideTheDashboard(): void
    {
        // Given
        $stream = $this->stream();

        // When
        $this->runWorker(new WorkerReporter(null, $stream, heartbeatIntervalMs: 0, clock: $this->clock));

        // Then
        static::assertSame([], $this->reports($stream));
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');
        static::assertNotFalse($stream);

        return $stream;
    }

    private function runWorker(WorkerReporter $reporter, int $concurrency = 1): InMemoryTransport
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new RoutedByClass('ok')));
        $transport->send(new Envelope(new RoutedByClass('fail')));

        $clock = $this->clock;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            RoutedByClass::class => [static function (RoutedByClass $message) use ($clock): void {
                $clock->sleep(self::HANDLING_SECONDS);
                if ('fail' === $message->payload) {
                    throw new RuntimeException("boom on line one\nboom on line two");
                }
            }],
        ]))]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($reporter);
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));

        // The time limit stops the worker in case the test fails
        new Worker(
            ['async' => $transport],
            $bus,
            $dispatcher,
            messageExecutionStrategy: $concurrency > 1 ? new DeferredExecutionStrategy($bus, $concurrency) : null,
        )->run(['sleep' => 0, 'fetch_size' => $concurrency, 'time_limit' => 5]);

        return $transport;
    }

    /**
     * @param resource $stream
     *
     * @return list<array<string, mixed>>
     */
    private function reports($stream): array
    {
        rewind($stream);
        $reports = [];
        foreach (explode("\n", trim((string) stream_get_contents($stream))) as $line) {
            $report = WorkerReport::decode($line);
            if (null !== $report) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /**
     * @param list<array<string, mixed>> $reports
     *
     * @return list<mixed> the types of the reports, heartbeats aside
     */
    private static function types(array $reports): array
    {
        return array_values(array_filter(
            array_column($reports, 'type'),
            static fn(mixed $type): bool => WorkerReport::HEARTBEAT !== $type,
        ));
    }

    /**
     * @param list<array<string, mixed>> $reports
     *
     * @return array<string, mixed>
     */
    private function first(array $reports, string $type): array
    {
        foreach ($reports as $report) {
            if ($type === $report['type']) {
                return $report;
            }
        }

        static::fail('No "' . $type . '" report.');
    }
}
