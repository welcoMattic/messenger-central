<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use DateTimeZone;
use Welcomattic\MessengerCentral\Failure\FailedMessage;
use Welcomattic\MessengerCentral\Tui\Format;

use function array_push;
use function count;
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
        bool $errors = false,
        int $columns = 80,
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
                'Failed%s%s%s',
                null === $message->from ? '' : ' on ' . Format::safe($message->from),
                null === $message->failedAt ? '' : ' at ' . Format::moment($message->failedAt, $timezone, $now),
                match ($message->retries) {
                    0 => '',
                    1 => ', after 1 retry',
                    default => sprintf(', after %d retries', $message->retries),
                },
            )),
        ];
        if ($errors) {
            return [...$lines, '', ...self::errors($message, $columns)];
        }

        if (null !== $message->error) {
            $lines[] =
                Format::color(Format::safe($message->error), 'red')
                . ([] === $message->errors ? '' : Format::dim('   e full error'));
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
     * Every error of the message in full, and the stack trace of the last one.
     *
     * @return list<string>
     */
    private static function errors(FailedMessage $message, int $columns): array
    {
        $width = $columns - 4;
        $lines = [];
        $count = count($message->errors);
        foreach ($message->errors as $index => $error) {
            $lines[] = Format::bold($count > 1 ? sprintf('Error %d of %d', $index + 1, $count) : 'Error');
            foreach (Format::wrap(Format::safe($error), $width) as $line) {
                $lines[] = '  ' . Format::color($line, 'red');
            }

            $lines[] = '';
        }

        if ([] === $lines) {
            $lines[] = Format::dim('The message does not tell its errors.');
            $lines[] = '';
        }

        if (null !== $message->trace) {
            $lines[] = Format::bold('Stack trace of the last error');
            foreach (explode("\n", $message->trace) as $frame) {
                foreach (Format::wrap(Format::safe($frame), $width) as $line) {
                    $lines[] = '  ' . Format::dim($line);
                }
            }
        }

        $lines[] = '';
        $lines[] = Format::dim('e back to the message');

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

        return [Format::color(
            sprintf('⏎ edit it and send it back to %s   r send it back as is   d remove it', $from),
            'cyan',
        )];
    }
}
