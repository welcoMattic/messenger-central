<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\View;

use Welcomattic\MessengerCentral\Tui\Format;

/**
 * Every key of the dashboard.
 */
final class HelpView
{
    private const int KEY_WIDTH = 9;

    /**
     * @param bool $parallel whether workers can handle several messages at a time
     *
     * @return list<string>
     */
    public static function render(bool $parallel): array
    {
        $sections = [
            'Everywhere' => [
                '↑ ↓' => 'move in the sidebar',
                '⏎' => 'open a transport, or run the action under the cursor',
                '←' => 'close the opened transport',
                '?' => 'show or hide this help',
                'q' => 'quit; it asks first when workers run, and q again kills the ones stopping',
            ],
            'Workers' => [
                's' => 'start or stop the workers of the selected transport',
                '+ -' => 'scale the workers up or down, before or after starting them',
                ...($parallel ? ['c' => 'messages handled at the same time by each worker'] : []),
            ],
            'Views' => [
                'a  Esc' => 'live view: workers, waiting and recent messages',
                'l' => 'output of the workers, like logs and dumps',
                'm' => 'message classes routed to the transport',
                'o' => 'options of the transport',
            ],
            'Failed messages, on a failure transport' => [
                '[ ]' => 'turn the pages',
                'r' => 'send the message back as is to the transport it failed on',
                '⏎' => 'edit the message first, then Ctrl+S sends it back, Esc cancels',
                'd' => 'remove the message for good',
                'e' => 'show the errors of the message in full',
                'R  D' => 'send every message back, remove every message',
            ],
        ];

        $lines = [Format::bold('Keys'), ''];
        foreach ($sections as $title => $keys) {
            $lines[] = Format::bold(Format::color($title, 'cyan'));
            foreach ($keys as $key => $description) {
                $lines[] = '  ' . Format::bold(Format::pad($key, self::KEY_WIDTH)) . ' ' . $description;
            }

            $lines[] = '';
        }

        return $lines;
    }
}
