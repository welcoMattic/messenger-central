<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\TestCase;
use RuntimeException;
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
    public function testItReportsWhatTheWorkerDoes(): void
    {
        // Given
        $stream = fopen('php://memory', 'w+');
        static::assertNotFalse($stream);
        $this->runWorker(new WorkerReporter('7', $stream, heartbeatIntervalMs: 0));

        // When
        $reports = $this->reports($stream);

        // Then
        $types = array_values(array_filter(
            array_column($reports, 'type'),
            static fn(mixed $type): bool => WorkerReport::HEARTBEAT !== $type,
        ));
        static::assertSame(
            [
                WorkerReport::STARTED,
                WorkerReport::RECEIVED,
                WorkerReport::HANDLED,
                WorkerReport::RECEIVED,
                WorkerReport::FAILED,
                WorkerReport::STOPPED,
            ],
            $types,
        );

        static::assertSame('7', $reports[0]['worker']);
        static::assertSame(['async'], $reports[0]['transports']);

        $handled = $this->first($reports, WorkerReport::HANDLED);
        static::assertSame('async', $handled['transport']);
        static::assertSame(RoutedByClass::class, $handled['class']);
        static::assertSame('1', $handled['id']);
        static::assertIsFloat($handled['duration_ms'] ?? null);
        static::assertIsInt($handled['memory'] ?? null);

        $failed = $this->first($reports, WorkerReport::FAILED);
        static::assertFalse($failed['will_retry']);
        static::assertSame('RuntimeException: boom on line one boom on line two', $failed['error']);

        static::assertContains(WorkerReport::HEARTBEAT, array_column($reports, 'type'));
    }

    public function testItStaysSilentOutsideTheDashboard(): void
    {
        // Given
        $stream = fopen('php://memory', 'w+');
        static::assertNotFalse($stream);

        // When
        $this->runWorker(new WorkerReporter(null, $stream, heartbeatIntervalMs: 0));

        // Then
        static::assertSame([], $this->reports($stream));
    }

    private function runWorker(WorkerReporter $reporter): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new RoutedByClass('ok')));
        $transport->send(new Envelope(new RoutedByClass('fail')));

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            RoutedByClass::class => [static function (RoutedByClass $message): void {
                if ('fail' === $message->payload) {
                    throw new RuntimeException("boom on line one\nboom on line two");
                }
            }],
        ]))]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($reporter);
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));

        new Worker(['async' => $transport], $bus, $dispatcher)->run(['sleep' => 0]);
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
