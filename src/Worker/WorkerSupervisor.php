<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Closure;

use function array_filter;
use function array_reverse;
use function array_values;
use function count;
use function microtime;
use function usleep;

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

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ProcessFactory $processFactory,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * Starts or stops workers until $count of them consume the transport.
     */
    public function scale(string $transport, int $count): void
    {
        $now = ($this->clock)();
        $this->workers[$transport] = array_values(array_filter(
            $this->workers[$transport] ?? [],
            static fn(WorkerProcess $worker): bool => $worker->status->isAlive(),
        ));

        $active = $this->active($transport);
        for ($i = count($active); $i < $count; ++$i) {
            $id = $this->nextId++;
            $worker = new WorkerProcess($id, $transport, $this->processFactory->create($transport, $id), $now);
            $worker->start();
            $this->workers[$transport][] = $worker;
        }

        foreach (array_reverse($active) as $i => $worker) {
            if ($i >= (count($active) - $count)) {
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
     * Stops every worker, waiting for them at most $timeout seconds.
     */
    public function stopAll(float $timeout = 5.0): void
    {
        $now = ($this->clock)();
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $worker->stop($now);
            }
        }

        $deadline = microtime(true) + $timeout;
        while ($this->hasRunning() && microtime(true) < $deadline) {
            usleep(50_000);
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
        $now = ($this->clock)();
        $changed = false;
        foreach ($this->workers as $transport => $workers) {
            foreach ($workers as $worker) {
                $status = $worker->status;
                foreach ($worker->poll($now) as $report) {
                    $this->activity($transport)->record($report, $worker->id, $now);
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
}
