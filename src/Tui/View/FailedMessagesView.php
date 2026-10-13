<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;

use function count;
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
    ): array {
        return [
            Format::bold(Format::safe($transport->name)) . '  ' . Format::color('[failure transport]', 'magenta'),
            Format::dim(Format::safe($transport->maskedDsn() ?? 'custom transport service')),
            '',
            ...self::messages($queue, $now, $timezone),
            '',
            Format::dim('Workers do not consume a failure transport. Open it (⏎) and pick a message'),
            Format::dim('to send it back to the transport it failed on, as is or edited.'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function messages(?QueueSnapshot $queue, float $now, DateTimeZone $timezone): array
    {
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

        $lines = [$title . $count];
        foreach ($queue->messages as $message) {
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

        $hidden = $count - count($queue->messages);
        if ($hidden > 0) {
            $lines[] = Format::dim(sprintf('  … and %d more', $hidden));
        }

        return $lines;
    }
}
