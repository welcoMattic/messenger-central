<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * A receiver whose broker cannot be reached.
 */
final class FailingReceiver implements ReceiverInterface, MessageCountAwareInterface
{
    public int $calls = 0;

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function getMessageCount(): int
    {
        ++$this->calls;

        throw new RuntimeException('Connection timed out');
    }
}
