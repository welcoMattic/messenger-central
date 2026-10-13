<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use Closure;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function array_column;
use function array_map;
use function array_slice;
use function in_array;
use function microtime;
use function usleep;

#[RequiresPhpExtension('pcntl')]
final class WorkerSupervisorTest extends TestCase
{
    private WorkerSupervisor $supervisor;
    private FakeProcessFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new FakeProcessFactory(messages: 3);
        $this->supervisor = new WorkerSupervisor($this->factory);
    }

    protected function tearDown(): void
    {
        $this->supervisor->stopAll(1.0);
    }

    public function testItStartsWorkersAndCollectsTheirReports(): void
    {
        // When
        $this->supervisor->scale('async', 2);
        $this->waitUntil(
            fn(): bool => (
                6 === $this->supervisor->activity('async')->received
                && $this->allIdle('async')
                && in_array('a log line', $this->supervisor->workers('async')[0]->output, true)
            ),
        );

        // Then
        static::assertSame([['async', 1, 1], ['async', 2, 1]], $this->factory->created);

        $activity = $this->supervisor->activity('async');
        static::assertSame(4, $activity->processed);
        static::assertSame(2, $activity->failed);
        static::assertSame('RuntimeException: boom', $activity->recent[0]['error'] ?? null);

        $worker = $this->supervisor->workers('async')[0];
        static::assertSame(WorkerStatus::Idle, $worker->status);
        static::assertNotNull($worker->pid);
        static::assertSame(2, $worker->processed);
        static::assertSame(1, $worker->failed);
        static::assertSame(2 * 1024 * 1024, $worker->memory);
        static::assertSame([], $worker->inFlight);
        static::assertContains('[OK] Consuming messages from transport "async".', $worker->output);
        static::assertContains('a log line', $worker->output, 'The error output is kept too');
    }

    public function testItScalesDownAndStops(): void
    {
        // Given
        $this->supervisor->scale('async', 3);
        $this->waitUntil(fn(): bool => $this->allIdle('async'));

        // When
        $this->supervisor->scale('async', 1);

        // Then
        static::assertSame(
            [1],
            array_map(static fn(WorkerProcess $worker): int => $worker->id, $this->supervisor->active('async')),
        );
        $this->waitUntil(fn(): bool => 1 === $this->alive('async'));
        static::assertSame(0, $this->supervisor->workers('async')[2]->exitCode);
        static::assertContains('a log line', $this->supervisor->workers('async')[2]->output);

        // When
        $this->supervisor->stop('async');
        $this->waitUntil(fn(): bool => !$this->supervisor->hasRunning());

        // Then
        static::assertSame([], $this->supervisor->active('async'));
    }

    public function testStopAllWaitsForTheWorkers(): void
    {
        // Given
        $this->supervisor->scale('async', 1);
        $this->supervisor->scale('high', 1);
        $this->waitUntil(fn(): bool => $this->allIdle('async') && $this->allIdle('high'));

        // When
        $this->supervisor->stopAll();

        // Then
        static::assertFalse($this->supervisor->hasRunning());
        static::assertSame(WorkerStatus::Exited, $this->supervisor->workers('high')[0]->status);
    }

    public function testItReplacesWorkersWhenTheConcurrencyChanges(): void
    {
        // Given
        $this->supervisor->scale('async', 2);
        $this->waitUntil(fn(): bool => $this->allIdle('async'));

        // When
        $this->supervisor->scale('async', 2, 4);

        // Then
        static::assertSame([['async', 3, 4], ['async', 4, 4]], array_slice($this->factory->created, 2));
        static::assertSame(
            [4, 4],
            array_map(
                static fn(WorkerProcess $worker): int => $worker->concurrency,
                $this->supervisor->active('async'),
            ),
        );
        $this->waitUntil(fn(): bool => 2 === $this->alive('async'));
    }

    public function testItTracksMessagesHandledAtTheSameTime(): void
    {
        // Given
        $this->factory->mode = 'interleaved';

        // When
        $this->supervisor->scale('async', 1, 3);
        $this->waitUntil(fn(): bool => 3 === $this->supervisor->activity('async')->received);

        // Then
        $worker = $this->supervisor->workers('async')[0];
        static::assertSame(WorkerStatus::Busy, $worker->status);
        static::assertSame(['1', '2', '3'], array_column($worker->inFlight, 'id'));

        // When
        $this->supervisor->stop('async');
        $this->waitUntil(fn(): bool => !$this->supervisor->hasRunning());

        // Then
        static::assertSame(3, $worker->processed);
        static::assertSame(
            '1',
            $this->supervisor->activity('async')->recent[0]['id'],
            'The oldest message is handled last',
        );
    }

    public function testAWorkerStoppedWhileBootingIsNotShownAsCrashed(): void
    {
        // Given
        $this->factory->mode = 'slow-boot';
        $this->supervisor->scale('async', 1);

        // When
        $this->supervisor->stop('async');
        $this->waitUntil(fn(): bool => !$this->supervisor->hasRunning());

        // Then
        $worker = $this->supervisor->workers('async')[0];
        static::assertSame(143, $worker->exitCode);
        static::assertTrue($worker->exitedCleanly());
    }

    public function testStopAllKillsTheWorkersThatDoNotStopInTime(): void
    {
        // Given
        $this->factory->mode = 'stubborn';
        $this->supervisor->scale('async', 1);
        $this->waitUntil(fn(): bool => $this->allIdle('async'));

        // When
        $startedAt = microtime(true);
        $this->supervisor->stopAll(0.5);

        // Then
        static::assertFalse($this->supervisor->hasRunning());
        static::assertGreaterThanOrEqual(0.5, microtime(true) - $startedAt, 'It waited for the worker');
        static::assertTrue($this->supervisor->workers('async')[0]->exitedCleanly(), 'It was asked to stop');
    }

    private function allIdle(string $transport): bool
    {
        $workers = $this->supervisor->workers($transport);
        foreach ($workers as $worker) {
            if (WorkerStatus::Idle !== $worker->status || 3 !== ($worker->processed + $worker->failed)) {
                return false;
            }
        }

        return [] !== $workers;
    }

    private function alive(string $transport): int
    {
        $alive = 0;
        foreach ($this->supervisor->workers($transport) as $worker) {
            $alive += $worker->status->isAlive() ? 1 : 0;
        }

        return $alive;
    }

    /**
     * @param Closure(): bool $condition
     */
    private function waitUntil(Closure $condition, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $this->supervisor->poll();
            if ($condition()) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        static::fail('The condition was not met in time.');
    }
}
