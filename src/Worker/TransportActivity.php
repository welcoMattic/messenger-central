<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use function array_slice;
use function array_unshift;
use function count;
use function floor;
use function is_float;
use function is_int;
use function is_string;
use function max;

/**
 * What happened on a transport since the dashboard started.
 */
final class TransportActivity
{
    public const string HANDLED = 'handled';
    public const string FAILED = 'failed';
    public const string RETRIED = 'retried';

    private const int MAX_RECENT = 50;
    private const int HISTORY_SECONDS = 120;

    public int $received = 0;
    public int $processed = 0;
    public int $failed = 0;
    public int $retried = 0;

    /** @var list<array{at: float, worker: int, class: string, id: string|null, status: string, duration: float|null, error: string|null}> most recent first */
    public array $recent = [];

    /** @var array<int, int> second => messages received during that second */
    private array $receivedPerSecond = [];

    /**
     * @param array<string, mixed> $report
     */
    public function record(array $report, int $workerId, float $now): void
    {
        switch ($report['type']) {
            case WorkerReport::RECEIVED:
                ++$this->received;
                $second = (int) floor($now);
                $this->receivedPerSecond[$second] = ($this->receivedPerSecond[$second] ?? 0) + 1;
                $this->prune($second);

                return;
            case WorkerReport::HANDLED:
                ++$this->processed;
                $this->remember($report, $workerId, $now, self::HANDLED);

                return;
            case WorkerReport::FAILED:
                $retry = true === ($report['will_retry'] ?? false);
                $retry ? ++$this->retried : ++$this->failed;
                $this->remember($report, $workerId, $now, $retry ? self::RETRIED : self::FAILED);

                return;
        }
    }

    /**
     * @return list<int> messages received per second, oldest first, the current second last
     */
    public function history(int $seconds, float $now): array
    {
        $current = (int) floor($now);
        $history = [];
        for ($second = $current - $seconds + 1; $second <= $current; ++$second) {
            $history[] = $this->receivedPerSecond[$second] ?? 0;
        }

        return $history;
    }

    /**
     * Average messages received per second over the last complete seconds.
     */
    public function rate(float $now, int $window = 5): float
    {
        $history = array_slice($this->history($window + 1, $now), 0, $window);
        $total = 0;
        foreach ($history as $count) {
            $total += $count;
        }

        return $total / max(1, count($history));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function remember(array $report, int $workerId, float $now, string $status): void
    {
        $duration = $report['duration_ms'] ?? null;

        array_unshift($this->recent, [
            'at' => $now,
            'worker' => $workerId,
            'class' => is_string($report['class'] ?? null) ? $report['class'] : '?',
            'id' => is_string($report['id'] ?? null) ? $report['id'] : null,
            'status' => $status,
            'duration' => is_float($duration) || is_int($duration) ? (float) $duration : null,
            'error' => is_string($report['error'] ?? null) ? $report['error'] : null,
        ]);

        if (count($this->recent) > self::MAX_RECENT) {
            $this->recent = array_slice($this->recent, 0, self::MAX_RECENT);
        }
    }

    private function prune(int $current): void
    {
        foreach ($this->receivedPerSecond as $second => $_) {
            if ($second > ($current - self::HISTORY_SECONDS)) {
                break;
            }

            unset($this->receivedPerSecond[$second]);
        }
    }
}
