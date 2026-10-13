<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;

use function array_slice;
use function ceil;
use function count;
use function max;
use function sprintf;

/**
 * The messages of a failure transport, which workers do not consume.
 */
final class FailedMessagesView
{
    /**
     * @return list<string>
     */
    public static function render(
        TransportDefinition $transport,
        ?QueueSnapshot $queue,
        float $now,
        DateTimeZone $timezone,
        int $page = 0,
        int $pageSize = 20,
    ): array {
        return [
            Format::bold(Format::safe($transport->name)) . '  ' . Format::color('[failure transport]', 'magenta'),
            Format::dim(Format::safe($transport->maskedDsn() ?? 'custom transport service')),
            '',
            ...self::messages($queue, $now, $timezone, $page, $pageSize),
            '',
            Format::dim('Workers do not consume a failure transport. Open it (⏎) and pick a message'),
            Format::dim('to send it back to the transport it failed on, as is or edited, or to remove it.'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function messages(
        ?QueueSnapshot $queue,
        float $now,
        DateTimeZone $timezone,
        int $page,
        int $pageSize,
    ): array {
        $title = Format::bold('Failed messages') . '  ';
        if (null === $queue) {
            return [$title . Format::dim('…')];
        }

        if (null !== $queue->error) {
            return [$title . Format::color(Format::safe($queue->error), 'red')];
        }

        if (null === $queue->messages) {
            return [
                $title . (null === $queue->count ? '' : (string) $queue->count),
                Format::dim('This transport cannot list its messages: they cannot be sent back from here.'),
            ];
        }

        $count = $queue->count ?? count($queue->messages);
        if (0 === $count) {
            return [$title . Format::dim('none')];
        }

        $pages = max(1, (int) ceil($count / $pageSize));
        $first = $page * $pageSize;
        $shown = array_slice($queue->messages, $first, $pageSize);
        $lines = [
            $title
                . $count
                . ($pages > 1 ? Format::dim(sprintf('   page %d of %d, [ and ] to turn', $page + 1, $pages)) : ''),
        ];
        if ([] === $shown) {
            // The page was just turned
            $lines[] = Format::dim('  …');

            return $lines;
        }

        foreach ($shown as $message) {
            $lines[] = sprintf(
                '  %s  %s%s%s%s',
                Format::dim(
                    null === $message['failed_at'] ? '?' : Format::moment($message['failed_at'], $timezone, $now),
                ),
                Format::safe(Format::shortClass($message['class'])),
                null === $message['id'] ? '' : Format::dim(' #' . Format::safe($message['id'])),
                null === $message['from'] ? '' : Format::dim(' from ' . Format::safe($message['from'])),
                null === $message['error'] ? '' : '  ' . Format::color(Format::safe($message['error']), 'red'),
            );
        }

        $hidden = $count - $first - count($shown);
        if ($hidden > 0) {
            $lines[] = Format::dim(sprintf('  … and %d more', $hidden));
        }

        return $lines;
    }
}
