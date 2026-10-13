<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;
use Welcomattic\MessengerCentral\Worker\WorkerReport;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;

use function microtime;
use function usleep;
use function var_export;

use const PHP_BINARY;

final class WorkerProcessTest extends TestCase
{
    private ?Process $process = null;

    protected function tearDown(): void
    {
        $this->process?->stop(0);
    }

    public function testItReadsReportsWrittenInSeveralChunks(): void
    {
        // Given
        $report = WorkerReport::encode(WorkerReport::STARTED, ['pid' => 42]);
        $worker = $this->worker(<<<PHP
            echo substr({$this->export($report)}, 0, 20); flush(); usleep(200000);
            echo substr({$this->export($report)}, 20); flush();
            fwrite(STDERR, 'err part 1'); usleep(200000); fwrite(STDERR, " err part 2\\n");
            echo 'last line without end';
            PHP);

        // When
        $reports = $this->pollUntilExited($worker);

        // Then
        static::assertCount(1, $reports);
        static::assertSame(42, $worker->pid);
        static::assertSame(['err part 1 err part 2', 'last line without end'], $worker->output);
        static::assertSame(0, $worker->exitCode);
        static::assertTrue($worker->exitedCleanly());
    }

    public function testItForgetsTheOutputOnceRead(): void
    {
        // Given
        $worker = $this->worker("echo str_repeat('a log line' . PHP_EOL, 1000);");

        // When
        $this->pollUntilExited($worker);

        // Then
        static::assertNotNull($this->process);
        static::assertSame('', $this->process->getOutput(), 'Process keeps nothing in memory');
        static::assertCount(200, $worker->output);
        static::assertCount(200, $worker->outputAt);
    }

    public function testItTracksMessagesHandledAtTheSameTime(): void
    {
        // Given
        $lines = [
            WorkerReport::encode(WorkerReport::RECEIVED, ['seq' => 1, 'class' => 'App\A', 'at' => 1000.0]),
            WorkerReport::encode(WorkerReport::RECEIVED, ['seq' => 2, 'class' => 'App\B', 'at' => 1001.0]),
            WorkerReport::encode(WorkerReport::HANDLED, ['seq' => 2, 'class' => 'App\B', 'duration_ms' => 5]),
            // Without a sequence number, like from an older version
            WorkerReport::encode(WorkerReport::RECEIVED, ['class' => 'App\C', 'at' => 1002.0]),
        ];
        $worker = $this->worker('echo ' . $this->export(implode('', $lines)) . '; sleep(10);', concurrency: 4);

        // When
        $this->pollUntil($worker, static fn(): bool => 2 === count($worker->inFlight));

        // Then
        static::assertSame(WorkerStatus::Busy, $worker->status);
        static::assertSame([1, -1], array_keys($worker->inFlight));
        static::assertSame('App\A', $worker->oldest()['class'] ?? null);
        static::assertSame(1000.0, $worker->oldest()['since'] ?? null);
        static::assertSame('App\B', $worker->last['class'] ?? null);
        static::assertSame(5.0, $worker->last['duration'] ?? null);
        static::assertSame(4, $worker->concurrency);
    }

    public function testItMatchesAReportWithoutSequenceNumberToTheOldestMessageOfItsClass(): void
    {
        // Given
        $lines = [
            WorkerReport::encode(WorkerReport::RECEIVED, ['class' => 'App\A', 'id' => '1']),
            WorkerReport::encode(WorkerReport::RECEIVED, ['class' => 'App\B', 'id' => '2']),
            WorkerReport::encode(WorkerReport::RECEIVED, ['class' => 'App\B', 'id' => '3']),
            WorkerReport::encode(WorkerReport::FAILED, ['class' => 'App\B', 'will_retry' => true]),
        ];
        $worker = $this->worker('echo ' . $this->export(implode('', $lines)) . '; sleep(10);');

        // When
        $this->pollUntil($worker, static fn(): bool => 1 === $worker->retried);

        // Then
        static::assertSame(['1', '3'], array_column($worker->inFlight, 'id'));
    }

    public function testAHeartbeatWithoutMessageInFlightResetsTheTracking(): void
    {
        // Given
        $lines = [
            WorkerReport::encode(WorkerReport::RECEIVED, ['seq' => 1, 'class' => 'App\A']),
            WorkerReport::encode(WorkerReport::HEARTBEAT, ['in_flight' => 0]),
        ];
        $worker = $this->worker('echo ' . $this->export(implode('', $lines)) . '; sleep(10);');

        // When
        $this->pollUntil($worker, static fn(): bool => WorkerStatus::Idle === $worker->status);

        // Then
        static::assertSame([], $worker->inFlight);
    }

    public function testItSummarizesTheErrorOfAWorkerThatCrashed(): void
    {
        // Given
        $worker = $this->worker(<<<'PHP'
            fwrite(STDERR, "\nIn Connection.php line 42:\n\n  Connection refused  \n\n\nmessenger:consume [--limit LIMIT]\n\n");
            exit(1);
            PHP);

        // When
        $this->pollUntilExited($worker);

        // Then
        static::assertSame(1, $worker->exitCode);
        static::assertFalse($worker->exitedCleanly());
        static::assertSame('Connection refused (Connection.php line 42)', $worker->error());
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAWorkerStoppedWhileBootingStoppedCleanly(): void
    {
        // Given
        $worker = $this->worker('sleep(10);');

        // When
        $worker->stop(microtime(true));
        $this->pollUntilExited($worker);

        // Then
        static::assertSame(143, $worker->exitCode, 'Killed by SIGTERM');
        static::assertTrue($worker->exitedCleanly());
    }

    #[RequiresPhpExtension('pcntl')]
    public function testItKillsAWorkerThatDoesNotStopInTime(): void
    {
        // Given
        $worker = $this->worker("pcntl_signal(SIGTERM, SIG_IGN); echo 'ready', PHP_EOL; sleep(10);", stopTimeout: 2.0);
        $this->pollUntil($worker, static fn(): bool => [] !== $worker->output);
        $stoppedAt = microtime(true);
        $worker->stop($stoppedAt);

        // When
        $worker->poll($stoppedAt + 1.0);
        usleep(100_000);
        $worker->poll($stoppedAt + 1.5);

        // Then
        static::assertSame(WorkerStatus::Stopping, $worker->status, 'Not killed before the stop timeout');

        // When
        $worker->poll($stoppedAt + 3.0);
        $this->pollUntilExited($worker);

        // Then
        static::assertSame(137, $worker->exitCode, 'Killed by SIGKILL');
        static::assertTrue($worker->exitedCleanly());
    }

    private function worker(string $code, int $concurrency = 1, float $stopTimeout = 10.0): WorkerProcess
    {
        $this->process = new Process([PHP_BINARY, '-r', $code]);
        $worker = new WorkerProcess(1, 'async', $this->process, microtime(true), $concurrency, $stopTimeout);
        $worker->start();

        return $worker;
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollUntilExited(WorkerProcess $worker): array
    {
        $reports = [];
        $this->pollUntil($worker, static fn(): bool => WorkerStatus::Exited === $worker->status, $reports);

        return $reports;
    }

    /**
     * @param \Closure(): bool                $condition
     * @param list<array<string, mixed>>       $reports
     */
    private function pollUntil(WorkerProcess $worker, \Closure $condition, array &$reports = []): void
    {
        $deadline = microtime(true) + 5;
        do {
            foreach ($worker->poll(microtime(true)) as $report) {
                $reports[] = $report;
            }

            if ($condition()) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        static::fail('The condition was not met in time.');
    }
}
