<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_filter;
use function array_reverse;
use function array_values;
use function count;

/**
 * Starts, scales and stops the workers of every transport.
 */
final class WorkerSupervisor
{
    /** @var array<string, list<WorkerProcess>> */
    private array $workers = [];

    /** @var array<string, TransportActivity> */
    private array $activities = [];

    private int $nextId = 1;

    /**
     * @param float $stopTimeout seconds given to a worker to stop before it is killed
     */
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly float $stopTimeout = 10.0,
    ) {}

    /**
     * Starts or stops workers until $count of them consume the transport,
     * each one handling up to $concurrency messages at the same time.
     */
    public function scale(string $transport, int $count, int $concurrency = 1): void
    {
        $now = $this->now();
        $this->workers[$transport] = array_values(array_filter(
            $this->workers[$transport] ?? [],
            static fn(WorkerProcess $worker): bool => $worker->status->isAlive(),
        ));

        $kept = [];
        foreach ($this->active($transport) as $worker) {
            if ($worker->concurrency === $concurrency) {
                $kept[] = $worker;

                continue;
            }

            // The concurrency of a running worker cannot change: it is replaced
            $worker->stop($now);
        }

        for ($i = count($kept); $i < $count; ++$i) {
            $id = $this->nextId++;
            $worker = new WorkerProcess(
                $id,
                $transport,
                $this->processFactory->create($transport, $id, $concurrency),
                $now,
                $concurrency,
                $this->stopTimeout,
            );
            $worker->start();
            $this->workers[$transport][] = $worker;
        }

        foreach (array_reverse($kept) as $i => $worker) {
            if ($i >= (count($kept) - $count)) {
                break;
            }

            $worker->stop($now);
        }
    }

    public function stop(string $transport): void
    {
        $this->scale($transport, 0);
    }

    /**
     * Stops every worker, waiting for them at most $timeout seconds (the
     * stop timeout by default) before killing them.
     */
    public function stopAll(?float $timeout = null): void
    {
        $timeout ??= $this->stopTimeout;
        $now = $this->now();
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $worker->stop($now);
            }
        }

        $deadline = $now + $timeout;
        while ($this->hasRunning() && $this->now() < $deadline) {
            $this->clock->sleep(0.05);
            $this->poll();
        }

        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $worker->terminate(0.0);
            }
        }

        $this->poll();
    }

    /**
     * Collects what workers reported since the last call.
     *
     * @return bool whether anything changed
     */
    public function poll(): bool
    {
        $now = $this->now();
        $changed = false;
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $status = $worker->status;
                foreach ($worker->poll($now) as $report) {
                    $this->activity($worker->transport)->record($report, $worker->id, $now);
                    $changed = true;
                }

                $changed = $changed || $status !== $worker->status;
            }
        }

        return $changed;
    }

    /**
     * @return list<WorkerProcess> the workers of the transport, exited ones included until the next scale
     */
    public function workers(string $transport): array
    {
        return $this->workers[$transport] ?? [];
    }

    /**
     * @return list<WorkerProcess> the workers that are not exited nor stopping
     */
    public function active(string $transport): array
    {
        return array_values(array_filter(
            $this->workers[$transport] ?? [],
            static fn(WorkerProcess $worker): bool => (
                $worker->status->isAlive()
                && WorkerStatus::Stopping !== $worker->status
            ),
        ));
    }

    public function activity(string $transport): TransportActivity
    {
        return $this->activities[$transport] ??= new TransportActivity();
    }

    public function hasRunning(): bool
    {
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                if ($worker->status->isAlive()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ($now->getMicrosecond() / 1_000_000);
    }
}
