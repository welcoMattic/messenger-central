<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;

use function array_slice;
use function count;
use function max;
use function sprintf;
use function usort;

/**
 * What the workers of a transport wrote, like logs and dumps, most recent last.
 */
final class LogsView
{
    /**
     * @param list<WorkerProcess> $workers
     *
     * @return list<string>
     */
    public static function render(
        TransportDefinition $transport,
        array $workers,
        DateTimeZone $timezone,
        int $rows,
    ): array {
        $lines = [
            Format::bold(sprintf('Output of the workers of %s', Format::safe($transport->name))),
            Format::dim('Logs, dumps and errors, besides the messages handled.'),
            '',
        ];

        $entries = [];
        foreach ($workers as $worker) {
            foreach ($worker->output as $index => $line) {
                $entries[] = ['at' => $worker->outputAt[$index] ?? 0.0, 'worker' => $worker->id, 'line' => $line];
            }
        }

        if ([] === $entries) {
            $lines[] = Format::dim([] === $workers ? 'No worker yet.' : 'The workers wrote nothing yet.');

            return $lines;
        }

        // Stable: lines of a worker read at the same time keep their order
        usort($entries, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        $room = max(1, $rows - count($lines));
        foreach (array_slice($entries, -$room) as $entry) {
            $lines[] = sprintf(
                '%s %s  %s',
                Format::dim(Format::time($entry['at'], $timezone)),
                Format::dim(sprintf('#%-3d', $entry['worker'])),
                Format::safe($entry['line']),
            );
        }

        return $lines;
    }
}
