<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function array_key_first;
use function array_pop;
use function array_slice;
use function count;
use function explode;
use function in_array;
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

    // POSIX values, so that ext-pcntl is not needed to send them
    private const int SIGTERM = 15;
    private const int SIGKILL = 9;

    // Exit codes of a process killed by one of these signals, or killed by
    // Process::stop(), which may lose its exit code (-1)
    private const array STOP_EXIT_CODES = [128 + self::SIGTERM, 128 + self::SIGKILL, -1];

    public WorkerStatus $status = WorkerStatus::Starting;
    public ?int $pid = null;
    public int $processed = 0;
    public int $failed = 0;
    public int $retried = 0;
    public int $memory = 0;
    public int $peakMemory = 0;
    public ?int $exitCode = null;

    /** @var array<int, array{class: string, id: string|null, retry: int, since: float}> messages being handled, oldest first */
    public array $inFlight = [];

    /** @var array{class: string, duration: float|null, at: float}|null the last message handled or failed */
    public ?array $last = null;

    /** @var list<string> output lines that are not reports, most recent last */
    public array $output = [];

    private string $buffer = '';
    private string $errorBuffer = '';
    private ?float $stopRequestedAt = null;
    private bool $stopReported = false;

    // Keys of the messages reported without a sequence number
    private int $untracked = 0;

    public function __construct(
        public readonly int $id,
        public readonly string $transport,
        private readonly Process $process,
        public readonly float $startedAt,
        public readonly int $concurrency = 1,
        private readonly float $stopTimeout = 10.0,
    ) {}

    public function start(): void
    {
        $this->process->start();
        $this->pid = $this->process->getPid();
    }

    /**
     * Asks the worker to stop after its current messages, and kills it if
     * it is still running after the stop timeout.
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
        // Reads the pipes, all of them once the process exited: nothing is
        // left behind when the worker is marked as exited below
        $running = $this->process->isRunning();

        // Process keeps everything a worker writes: drop it once read
        $output = $this->process->getIncrementalOutput();
        $this->process->clearOutput();
        $errorOutput = $this->process->getIncrementalErrorOutput();
        $this->process->clearErrorOutput();

        $reports = [];
        foreach ($this->lines($output, $this->buffer) as $line) {
            $report = WorkerReport::decode($line);
            if (null === $report) {
                $this->appendOutput($line);

                continue;
            }

            $reports[] = $report;
            $this->apply($report, $now);
        }

        foreach ($this->lines($errorOutput, $this->errorBuffer) as $line) {
            $this->appendOutput($line);
        }

        if ($this->status->isAlive() && !$running) {
            $this->appendOutput($this->buffer);
            $this->appendOutput($this->errorBuffer);
            $this->buffer = '';
            $this->errorBuffer = '';
            $this->status = WorkerStatus::Exited;
            $this->exitCode = $this->process->getExitCode();
            $this->inFlight = [];
        }

        if (
            WorkerStatus::Stopping === $this->status
            && null !== $this->stopRequestedAt
            && ($now - $this->stopRequestedAt) > $this->stopTimeout
        ) {
            $this->signal(self::SIGKILL);
        }

        return $reports;
    }

    /**
     * When the worker is killed if it did not stop by then.
     */
    public function killAt(): ?float
    {
        return null === $this->stopRequestedAt ? null : $this->stopRequestedAt + $this->stopTimeout;
    }

    /**
     * Whether the dashboard asked the worker to stop.
     */
    public function stopRequested(): bool
    {
        return null !== $this->stopRequestedAt;
    }

    /**
     * Whether the worker exited normally, or because it was asked to stop.
     */
    public function exitedCleanly(): bool
    {
        if (0 === $this->exitCode) {
            return true;
        }

        // Killed before it could handle the signal (while booting), or after the stop timeout
        return (
            (null !== $this->stopRequestedAt || $this->stopReported)
            && in_array($this->exitCode, self::STOP_EXIT_CODES, true)
        );
    }

    /**
     * @return array{class: string, id: string|null, retry: int, since: float}|null the message handled for the longest time
     */
    public function oldest(): ?array
    {
        $key = array_key_first($this->inFlight);

        return null === $key ? null : $this->inFlight[$key];
    }

    /**
     * The error that made the worker exit, as far as its output tells.
     */
    public function error(): ?string
    {
        return ConsoleError::summarize($this->output);
    }

    /**
     * Splits a chunk into complete lines, keeping the last partial one in the buffer.
     *
     * @return list<string>
     */
    private function lines(string $chunk, string &$buffer): array
    {
        if ('' === $chunk) {
            return [];
        }

        $lines = explode("\n", $buffer . $chunk);
        $buffer = array_pop($lines);

        return $lines;
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
                $key = is_int($report['seq'] ?? null) ? $report['seq'] : --$this->untracked;
                $this->inFlight[$key] = [
                    'class' => is_string($report['class'] ?? null) ? $report['class'] : '?',
                    'id' => is_string($report['id'] ?? null) ? $report['id'] : null,
                    'retry' => is_int($report['retry'] ?? null) ? $report['retry'] : 0,
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
                if (0 === ($report['in_flight'] ?? null)) {
                    // Reports are written in order: every message received before was handled
                    $this->inFlight = [];
                }

                if (!$stopping && [] === $this->inFlight) {
                    $this->status = WorkerStatus::Idle;
                }

                break;
            case WorkerReport::STOPPED:
                // The worker stops by itself (time or memory limit...), or as asked
                $this->stopReported = true;
                if ($this->status->isAlive()) {
                    $this->status = WorkerStatus::Stopping;
                }

                break;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function finish(array $report, float $now, bool $stopping): void
    {
        $class = is_string($report['class'] ?? null) ? $report['class'] : null;
        $message = null;
        foreach ($this->inFlight as $key => $candidate) {
            $matches = is_int($report['seq'] ?? null)
                ? $key === $report['seq']
                // Without a sequence number, the oldest message of the same class
                : null === $class || $candidate['class'] === $class;
            if ($matches) {
                $message = $candidate;
                unset($this->inFlight[$key]);

                break;
            }
        }

        $duration = $report['duration_ms'] ?? null;
        $this->last = [
            'class' => $message['class'] ?? $class ?? '?',
            'duration' => is_int($duration) || is_float($duration) ? (float) $duration : null,
            'at' => $now,
        ];

        if (!$stopping) {
            $this->status = [] === $this->inFlight ? WorkerStatus::Idle : WorkerStatus::Busy;
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
