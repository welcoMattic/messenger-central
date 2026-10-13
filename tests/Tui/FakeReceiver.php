<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tui;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;

final class FakeReceiver implements ListableReceiverInterface, MessageCountAwareInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function all(?int $limit = null): iterable
    {
        yield new Envelope(new RoutedByClass(), [new TransportMessageIdStamp(41)]);
        yield new Envelope(new RoutedByClass(), [new TransportMessageIdStamp(42)]);
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }

    public function getMessageCount(): int
    {
        return 42;
    }
}
