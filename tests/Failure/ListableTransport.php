<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_slice;
use function count;

/**
 * An in-memory transport that lists its messages, like the Doctrine one.
 */
final class ListableTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    /** @var array<string, Envelope> */
    public array $envelopes = [];

    public bool $failOnSend = false;

    private int $lastId = 0;

    public function send(Envelope $envelope): Envelope
    {
        if ($this->failOnSend) {
            throw new RuntimeException('The broker went away');
        }

        $id = (string) ++$this->lastId;
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class)->with(new TransportMessageIdStamp($id));
        $this->envelopes[$id] = $envelope;

        return $envelope;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void
    {
        unset($this->envelopes[(string) $envelope->last(TransportMessageIdStamp::class)?->getId()]);
    }

    public function all(?int $limit = null): iterable
    {
        return array_slice($this->envelopes, 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        return $this->envelopes[(string) $id] ?? null;
    }

    public function getMessageCount(): int
    {
        return count($this->envelopes);
    }
}
