<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_filter;
use function array_reverse;
use function array_values;
use function count;
use function min;

/**
 * Starts, scales and stops the workers of every transport.
 *
 * Workers that exit by themselves (time, memory or message limit, crash)
 * are started again while their transport is meant to be consumed: at once
 * when they stopped normally, after a growing delay when they crashed.
 */
final class WorkerSupervisor
{
    private const float MAX_RESTART_DELAY = 30.0;

    // A worker that crashes after running that long starts the delays over
    private const float STABLE_AFTER = 60.0;

    // Exited workers kept to be shown, besides the running ones
    private const int KEPT_EXITED = 3;

    /** @var array<string, list<WorkerProcess>> */
    private array $workers = [];

    /** @var array<string, array{count: int, concurrency: int}> the workers each transport is meant to have */
    private array $desired = [];

    /** @var array<string, list<float>> when to start again the workers that exited by themselves */
    private array $restarts = [];

    /** @var array<string, int> crashes in a row of the workers of each transport */
    private array $crashes = [];

    /** @var array<string, int> workers started again, per transport */
    private array $restarted = [];

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
        unset($this->restarts[$transport]);
        $this->desired[$transport] = ['count' => $count, 'concurrency' => $concurrency];
        if (0 === $count) {
            unset($this->desired[$transport], $this->crashes[$transport]);
        }

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
            $this->spawn($transport, $concurrency, $now);
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
     * Asks every worker to stop, without waiting for them: poll() kills the
     * ones still running after the stop timeout.
     */
    public function stopEach(): void
    {
        $now = $this->now();
        $this->desired = [];
        $this->restarts = [];
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $worker->stop($now);
            }
        }
    }

    /**
     * Kills every worker now.
     */
    public function killAll(): void
    {
        $this->desired = [];
        $this->restarts = [];
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                $worker->terminate(0.0);
            }
        }

        $this->poll();
    }

    /**
     * @return array{workers: int, messages: int, kill_at: float|null} the workers still running, the messages
     *                                                                they handle, and when the first one is killed
     */
    public function stopping(): array
    {
        $running = 0;
        $messages = 0;
        $killAt = null;
        foreach ($this->workers as $workers) {
            foreach ($workers as $worker) {
                if (!$worker->status->isAlive()) {
                    continue;
                }

                ++$running;
                $messages += count($worker->inFlight);
                $at = $worker->killAt();
                $killAt = null === $at ? $killAt : min($at, $killAt ?? $at);
            }
        }

        return ['workers' => $running, 'messages' => $messages, 'kill_at' => $killAt];
    }

    /**
     * Stops every worker, waiting for them at most $timeout seconds (the
     * stop timeout by default) before killing them.
     */
    public function stopAll(?float $timeout = null): void
    {
        $timeout ??= $this->stopTimeout;
        $now = $this->now();
        $this->stopEach();

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

                if ($status === $worker->status) {
                    continue;
                }

                $changed = true;
                if (WorkerStatus::Exited === $worker->status) {
                    $this->exited($worker, $now);
                }
            }
        }

        return $this->restart($now) || $changed;
    }

    /**
     * How many workers the transport is meant to have, whether they run or
     * are about to be started again.
     */
    public function desired(string $transport): int
    {
        return $this->desired[$transport]['count'] ?? 0;
    }

    /**
     * When the next worker of the transport is started again, if one is waiting.
     */
    public function nextRestart(string $transport): ?float
    {
        $restarts = $this->restarts[$transport] ?? [];

        return [] === $restarts ? null : min($restarts);
    }

    /**
     * How many workers of the transport were started again since the dashboard started.
     */
    public function restarted(string $transport): int
    {
        return $this->restarted[$transport] ?? 0;
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

    private function spawn(string $transport, int $concurrency, float $now): void
    {
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

    /**
     * Plans to start again a worker that exited by itself.
     */
    private function exited(WorkerProcess $worker, float $now): void
    {
        $transport = $worker->transport;
        if (!isset($this->desired[$transport]) || $worker->stopRequested()) {
            return;
        }

        if ($worker->exitedCleanly()) {
            $this->crashes[$transport] = 0;
            $this->restarts[$transport][] = $now;

            return;
        }

        // 1, 2, 4... seconds, so that a worker that cannot start does not loop
        $crashes = ($now - $worker->startedAt) >= self::STABLE_AFTER ? 1 : ($this->crashes[$transport] ?? 0) + 1;
        $this->crashes[$transport] = $crashes;
        $this->restarts[$transport][] = $now + min(self::MAX_RESTART_DELAY, 2 ** ($crashes - 1));
    }

    /**
     * @return bool whether a worker was started
     */
    private function restart(float $now): bool
    {
        $started = false;
        foreach ($this->restarts as $transport => $restarts) {
            $desired = $this->desired[$transport] ?? null;
            $pending = [];
            foreach ($restarts as $at) {
                if ($at > $now) {
                    $pending[] = $at;

                    continue;
                }

                if (null !== $desired && count($this->active($transport)) < $desired['count']) {
                    $this->spawn($transport, $desired['concurrency'], $now);
                    $this->restarted[$transport] = $this->restarted($transport) + 1;
                    $started = true;
                }
            }

            $this->restarts[$transport] = $pending;
            $this->prune($transport);
        }

        return $started;
    }

    /**
     * Forgets the oldest exited workers, which pile up as workers are started again.
     */
    private function prune(string $transport): void
    {
        $exited = 0;
        $kept = [];
        foreach (array_reverse($this->workers[$transport] ?? []) as $worker) {
            if (WorkerStatus::Exited === $worker->status && ++$exited > self::KEPT_EXITED) {
                continue;
            }

            $kept[] = $worker;
        }

        $this->workers[$transport] = array_reverse($kept);
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ($now->getMicrosecond() / 1_000_000);
    }
}
