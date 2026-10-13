<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;
use Welcomattic\MessengerCentral\Worker\TransportActivity;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;

use function array_push;
use function array_slice;
use function ceil;
use function count;
use function max;
use function min;
use function sprintf;

/**
 * Live view of a transport: incoming rate, workers, waiting and recent messages.
 */
final class ActivityView
{
    private const int MAX_WORKER_LINES = 8;
    private const int MAX_QUEUE_LINES = 5;
    private const float RECENTLY_BUSY_SECONDS = 1.0;

    /**
     * @param list<WorkerProcess> $workers
     *
     * @return list<string>
     */
    public static function render(
        TransportDefinition $transport,
        array $workers,
        TransportActivity $activity,
        ?QueueSnapshot $queue,
        int $desiredScale,
        int $concurrency,
        float $now,
        DateTimeZone $timezone,
        int $columns,
        int $rows,
        ?float $restartAt = null,
        int $restarted = 0,
    ): array {
        $lines = [
            self::title($transport, $workers),
            Format::dim(Format::safe($transport->maskedDsn() ?? 'custom transport service')),
            '',
            ...self::incoming($activity, $now, $columns),
            '',
            ...self::workers($transport, $workers, $desiredScale, $concurrency, $now, $restartAt, $restarted),
            '',
            ...self::queue($queue),
            '',
        ];

        $remaining = $rows - count($lines) - 1;
        if ($remaining > 0) {
            $lines[] = Format::bold('Recent');
            foreach (self::recent($activity, $timezone, $remaining) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<WorkerProcess> $workers
     */
    private static function title(TransportDefinition $transport, array $workers): string
    {
        $running = 0;
        $stopping = 0;
        foreach ($workers as $worker) {
            match ($worker->status) {
                WorkerStatus::Stopping => ++$stopping,
                WorkerStatus::Exited => null,
                default => ++$running,
            };
        }

        $status = match (true) {
            $running > 0 => Format::color(
                sprintf('● %d worker%s running', $running, 1 === $running ? '' : 's'),
                'green',
            ),
            $stopping > 0 => Format::color('◌ stopping', 'yellow'),
            !$transport->consumable => Format::color('not consumable', 'gray'),
            default => Format::color('○ stopped', 'gray'),
        };

        $tags = $transport->failureTransport ? '  ' . Format::color('[failure transport]', 'magenta') : '';

        return Format::bold(Format::safe($transport->name)) . '  ' . $status . $tags;
    }

    /**
     * @return list<string>
     */
    private static function incoming(TransportActivity $activity, float $now, int $columns): array
    {
        $width = max(10, min(60, $columns - 40));

        return [
            sprintf(
                '%s  %s  %.1f msg/s  %s',
                Format::bold('Incoming'),
                Format::color(Format::sparkline($activity->history($width, $now)), 'cyan'),
                $activity->rate($now),
                Format::dim(sprintf('%d received', $activity->received)),
            ),
        ];
    }

    /**
     * @param list<WorkerProcess> $workers
     *
     * @return list<string>
     */
    private static function workers(
        TransportDefinition $transport,
        array $workers,
        int $desiredScale,
        int $concurrency,
        float $now,
        ?float $restartAt,
        int $restarted,
    ): array {
        $lines = [
            Format::bold('Workers')
                . ($concurrency > 1 ? Format::dim(sprintf('  %d messages at a time each', $concurrency)) : '')
                . (
                    $restarted > 0
                        ? Format::dim(sprintf('  started again %d time%s', $restarted, 1 === $restarted ? '' : 's'))
                        : ''
                ),
        ];
        $restart = null === $restartAt
            ? []
            : [Format::color(
                $restartAt > $now
                    ? sprintf(' ◌ a worker starts again in %d s', (int) ceil($restartAt - $now))
                    : ' ◌ a worker starts again…',
                'yellow',
            )];
        if ([] === $workers) {
            if ([] !== $restart) {
                return [...$lines, ...$restart];
            }

            $lines[] = $transport->consumable
                ? Format::dim(sprintf(
                    'No worker. Press s to start %d worker%s, + and - to change.',
                    $desiredScale,
                    1 === $desiredScale ? '' : 's',
                ))
                : Format::dim('This transport cannot be consumed.');

            return $lines;
        }

        foreach (array_slice($workers, 0, self::MAX_WORKER_LINES) as $worker) {
            $lines[] = self::worker($worker, $now);
        }

        if (count($workers) > self::MAX_WORKER_LINES) {
            $lines[] = Format::dim(sprintf('  … and %d more', count($workers) - self::MAX_WORKER_LINES));
        }

        array_push($lines, ...$restart);

        return $lines;
    }

    private static function worker(WorkerProcess $worker, float $now): string
    {
        // Fast messages are handled between two refreshes: a worker that just
        // finished one is shown as busy, which is what it is most of the time
        $last = $worker->last;
        $recentlyBusy = null !== $last && ($now - $last['at']) < self::RECENTLY_BUSY_SECONDS;

        $icon = match ($worker->status) {
            WorkerStatus::Busy => Format::color(Format::spinner($now), 'cyan'),
            WorkerStatus::Idle => $recentlyBusy
                ? Format::color(Format::spinner($now), 'cyan')
                : Format::color('●', 'green'),
            WorkerStatus::Starting => Format::color(Format::spinner($now), 'gray'),
            WorkerStatus::Stopping => Format::color('◌', 'yellow'),
            WorkerStatus::Exited => Format::color('✗', $worker->exitedCleanly() ? 'gray' : 'red'),
        };

        $head = sprintf(' %s #%-3d %-11s', $icon, $worker->id, null === $worker->pid ? '' : 'pid ' . $worker->pid);
        $counters =
            Format::color('✓ ' . $worker->processed, 'green')
            . ($worker->failed > 0 ? ' ' . Format::color('✗ ' . $worker->failed, 'red') : '')
            . ($worker->retried > 0 ? ' ' . Format::color('↻ ' . $worker->retried, 'yellow') : '');
        $memory = $worker->memory > 0 ? Format::dim(Format::bytes($worker->memory)) . '  ' : '';

        if (WorkerStatus::Exited === $worker->status) {
            if ($worker->exitedCleanly()) {
                return $head . Format::dim('stopped') . '  ' . $counters;
            }

            return sprintf(
                '%s%s  %s  %s',
                $head,
                Format::color(sprintf('exited with code %s', $worker->exitCode ?? '?'), 'red'),
                $counters,
                Format::dim(Format::safe($worker->error() ?? '')),
            );
        }

        $oldest = $worker->oldest();
        $inFlight = count($worker->inFlight);
        $doing = match (true) {
            WorkerStatus::Stopping === $worker->status => Format::dim(
                0 === $inFlight
                    ? 'stopping…'
                    : sprintf('stopping, finishing %d message%s…', $inFlight, 1 === $inFlight ? '' : 's'),
            ),
            null !== $oldest => (
                $worker->concurrency > 1
                    ? Format::color(sprintf('%d/%d busy', $inFlight, $worker->concurrency), 'cyan') . '  '
                    : ''
            )
                . Format::safe($oldest['class'])
                . (null === $oldest['id'] ? '' : Format::dim(' #' . Format::safe($oldest['id'])))
                . ($oldest['retry'] > 0 ? Format::color(' ↻' . $oldest['retry'], 'yellow') : '')
                . '  '
                . Format::color(Format::duration(($now - $oldest['since']) * 1000), 'cyan'),
            WorkerStatus::Starting === $worker->status => Format::dim('starting…'),
            $recentlyBusy => Format::dim(Format::safe($last['class']))
                . '  '
                . Format::dim(Format::duration($last['duration'])),
            default => Format::dim('waiting for messages'),
        };

        return $head . $memory . $counters . '  ' . $doing;
    }

    /**
     * @return list<string>
     */
    private static function queue(?QueueSnapshot $queue): array
    {
        $title = Format::bold('Queue') . '  ';
        if (null === $queue) {
            return [$title . Format::dim('…')];
        }

        if (null !== $queue->error) {
            return [$title . Format::color(Format::safe($queue->error), 'red')];
        }

        $lines = [
            $title
                . (
                    null === $queue->count
                        ? Format::dim('this transport does not tell how many messages it holds')
                        : sprintf('%d waiting', $queue->count)
                ),
        ];

        foreach (array_slice($queue->messages ?? [], 0, self::MAX_QUEUE_LINES) as $message) {
            $lines[] =
                '  · '
                . Format::safe($message['class'])
                . (null === $message['id'] ? '' : Format::dim(' #' . Format::safe($message['id'])));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function recent(TransportActivity $activity, DateTimeZone $timezone, int $limit): array
    {
        if ([] === $activity->recent) {
            return [Format::dim('  Nothing handled yet.')];
        }

        $lines = [];
        foreach (array_slice($activity->recent, 0, $limit) as $event) {
            $icon = match ($event['status']) {
                TransportActivity::FAILED => Format::color('✗', 'red'),
                TransportActivity::RETRIED => Format::color('↻', 'yellow'),
                default => Format::color('✓', 'green'),
            };

            $line = sprintf(
                ' %s %s  %s  %s',
                Format::dim(Format::time($event['at'], $timezone)),
                $icon,
                Format::safe($event['class']),
                Format::dim(sprintf(
                    '%s  #%d%s',
                    Format::duration($event['duration']),
                    $event['worker'],
                    $event['retry'] > 0 ? sprintf('  retry %d', $event['retry']) : '',
                )),
            );
            if (null !== $event['error']) {
                $line .= '  ' . Format::color(Format::safe($event['error']), 'red');
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
