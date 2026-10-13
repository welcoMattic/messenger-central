<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\Format;

use function count;
use function sprintf;

/**
 * The message classes routed to a transport.
 */
final class MessagesView
{
    /**
     * @return list<string>
     */
    public static function render(TransportDefinition $transport): array
    {
        $lines = [
            Format::bold(sprintf('Message classes routed to %s', Format::safe($transport->name)))
                . '  '
                . Format::dim(sprintf('(%d)', count($transport->messageClasses))),
            '',
        ];

        if ([] === $transport->messageClasses) {
            $lines[] = Format::dim('No known message class is routed to this transport.');
            $lines[] = Format::dim(
                'Classes come from the routing configuration and the #[AsMessage] attribute of handled messages.',
            );

            return $lines;
        }

        foreach ($transport->messageClasses as $class) {
            $lines[] = '  · ' . Format::safe($class);
        }

        return $lines;
    }
}
