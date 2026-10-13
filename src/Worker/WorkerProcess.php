<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function array_pop;
use function array_slice;
use function count;
use function explode;
use function is_float;
use function is_int;
use function is_string;
use function rtrim;
use function trim;

/**
 * One "messenger:consume" process, and what it reported so far.
 */
final class WorkerProcess
{
    private const int MAX_OUTPUT_LINES = 50;
    private const float KILL_AFTER_SECONDS = 10.0;

    // POSIX values, so that ext-pcntl is not needed to send them
    private const int SIGTERM = 15;
    private const int SIGKILL = 9;

    public WorkerStatus $status = WorkerStatus::Starting;
    public ?int $pid = null;
    public int $processed = 0;
    public int $failed = 0;
    public int $retried = 0;
    public int $memory = 0;
    public int $peakMemory = 0;
    public ?int $exitCode = null;

    /** @var array{class: string, id: string|null, since: float}|null */
    public ?array $current = null;

    /** @var array{class: string, duration: float|null, at: float}|null the last message handled or failed */
    public ?array $last = null;

    /** @var list<string> output lines that are not reports, most recent last */
    public array $output = [];

    private string $buffer = '';
    private ?float $stopRequestedAt = null;

    public function __construct(
        public readonly int $id,
        public readonly string $transport,
        private readonly Process $process,
        public readonly float $startedAt,
    ) {}

    public function start(): void
    {
        $this->process->start();
        $this->pid = $this->process->getPid();
    }

    /**
     * Asks the worker to stop after its current message.
     */
    public function stop(float $now): void
    {
        if (!$this->status->isAlive() || null !== $this->stopRequestedAt) {
            return;
        }

        $this->stopRequestedAt = $now;
        $this->status = WorkerStatus::Stopping;
        $this->signal(self::SIGTERM);
    }

    /**
     * Stops the worker, waiting for it at most $timeout seconds.
     */
    public function terminate(float $timeout): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop($timeout);
        }
    }

    /**
     * Reads what the worker wrote since the last call.
     *
     * @return list<array<string, mixed>> the reports received
     */
    public function poll(float $now): array
    {
        $reports = $this->read($this->process->getIncrementalOutput(), true);
        foreach ($this->read($this->process->getIncrementalErrorOutput(), false) as $report) {
            $reports[] = $report;
        }

        foreach ($reports as $report) {
            $this->apply($report, $now);
        }

        if ($this->status->isAlive() && !$this->process->isRunning()) {
            $this->flush();
            $this->status = WorkerStatus::Exited;
            $this->exitCode = $this->process->getExitCode();
            $this->current = null;
        }

        if (
            WorkerStatus::Stopping === $this->status
            && null !== $this->stopRequestedAt
            && ($now - $this->stopRequestedAt) > self::KILL_AFTER_SECONDS
        ) {
            $this->signal(self::SIGKILL);
        }

        return $reports;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $chunk, bool $parseReports): array
    {
        if ('' === $chunk) {
            return [];
        }

        if (!$parseReports) {
            foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
                $this->appendOutput($line);
            }

            return [];
        }

        $lines = explode("\n", $this->buffer . $chunk);
        $this->buffer = array_pop($lines);

        $reports = [];
        foreach ($lines as $line) {
            $report = WorkerReport::decode($line);
            if (null !== $report) {
                $reports[] = $report;

                continue;
            }

            $this->appendOutput($line);
        }

        return $reports;
    }

    private function flush(): void
    {
        if ('' !== $this->buffer) {
            $this->appendOutput($this->buffer);
            $this->buffer = '';
        }
    }

    private function appendOutput(string $line): void
    {
        if ('' === trim($line)) {
            return;
        }

        $this->output[] = rtrim($line, "\r");
        if (count($this->output) > self::MAX_OUTPUT_LINES) {
            $this->output = array_slice($this->output, -self::MAX_OUTPUT_LINES);
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function apply(array $report, float $now): void
    {
        if (is_int($report['memory'] ?? null)) {
            $this->memory = $report['memory'];
        }

        if (is_int($report['peak_memory'] ?? null)) {
            $this->peakMemory = $report['peak_memory'];
        }

        $stopping = WorkerStatus::Stopping === $this->status;

        switch ($report['type']) {
            case WorkerReport::STARTED:
                if (is_int($report['pid'] ?? null)) {
                    $this->pid = $report['pid'];
                }

                if (!$stopping) {
                    $this->status = WorkerStatus::Idle;
                }

                break;
            case WorkerReport::RECEIVED:
                $this->current = [
                    'class' => is_string($report['class'] ?? null) ? $report['class'] : '?',
                    'id' => is_string($report['id'] ?? null) ? $report['id'] : null,
                    'since' => is_float($report['at'] ?? null) ? $report['at'] : $now,
                ];
                if (!$stopping) {
                    $this->status = WorkerStatus::Busy;
                }

                break;
            case WorkerReport::HANDLED:
                ++$this->processed;
                $this->finish($report, $now, $stopping);

                break;
            case WorkerReport::FAILED:
                $willRetry = true === ($report['will_retry'] ?? false);
                $this->retried += $willRetry ? 1 : 0;
                $this->failed += $willRetry ? 0 : 1;
                $this->finish($report, $now, $stopping);

                break;
            case WorkerReport::HEARTBEAT:
                if (!$stopping && null === $this->current) {
                    $this->status = WorkerStatus::Idle;
                }

                break;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function finish(array $report, float $now, bool $stopping): void
    {
        $duration = $report['duration_ms'] ?? null;
        $this->last = [
            'class' => $this->current['class'] ?? (is_string($report['class'] ?? null) ? $report['class'] : '?'),
            'duration' => is_int($duration) || is_float($duration) ? (float) $duration : null,
            'at' => $now,
        ];
        $this->current = null;
        if (!$stopping) {
            $this->status = WorkerStatus::Idle;
        }
    }

    private function signal(int $signal): void
    {
        if (!$this->process->isRunning()) {
            return;
        }

        try {
            $this->process->signal($signal);
        } catch (ExceptionInterface) {
            // The process exited in between
            return;
        }
    }
}
