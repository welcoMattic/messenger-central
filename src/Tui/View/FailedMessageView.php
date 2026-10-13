<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Failure\FailedMessage;
use Welcomattic\MessengerCentral\Tui\Format;

use function array_push;
use function explode;
use function implode;
use function sprintf;

/**
 * A message of a failure transport, as it will be edited.
 */
final class FailedMessageView
{
    /**
     * @param string|null $error why the message could not be read
     *
     * @return list<string>
     */
    public static function render(
        string $transport,
        ?FailedMessage $message,
        ?string $error,
        float $now,
        DateTimeZone $timezone,
    ): array {
        if (null !== $error) {
            return [Format::color(Format::safe($error), 'red')];
        }

        if (null === $message) {
            return [Format::dim(sprintf('This message is not in %s anymore.', Format::safe($transport)))];
        }

        $lines = [
            Format::bold(Format::safe($message->class)) . Format::dim(' #' . Format::safe($message->id)),
            Format::dim(sprintf(
                'Failed%s%s',
                null === $message->from ? '' : ' on ' . Format::safe($message->from),
                null === $message->failedAt ? '' : ' at ' . Format::moment($message->failedAt, $timezone, $now),
            )),
        ];
        if (null !== $message->error) {
            $lines[] = Format::color(Format::safe($message->error), 'red');
        }

        $lines[] = '';
        array_push($lines, ...self::actions($message));
        if (null === $message->json) {
            return $lines;
        }

        $lines[] = '';
        foreach (explode("\n", $message->json) as $line) {
            $lines[] = Format::safe($line);
        }

        if ([] !== $message->kept) {
            $lines[] = '';
            $lines[] = Format::dim('Not shown, kept as they are: ' . Format::safe(implode(', ', $message->kept)));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function actions(FailedMessage $message): array
    {
        if (null === $message->from) {
            return [Format::color('It does not tell which transport it failed on: it cannot be sent back.', 'yellow')];
        }

        $from = Format::safe($message->from);
        if (null === $message->json) {
            return [
                Format::color('This message cannot be edited.', 'yellow'),
                Format::color(sprintf('r send it back to %s as is', $from), 'cyan'),
            ];
        }

        return [Format::color(sprintf('⏎ edit it and send it back to %s   r send it back as is', $from), 'cyan')];
    }
}
