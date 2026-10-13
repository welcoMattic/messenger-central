<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Failure;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Welcomattic\MessengerCentral\Transport\QueueInspector;

use function array_push;
use function in_array;
use function is_a;
use function sprintf;

/**
 * Reads the messages of a failure transport, and sends them back to the
 * transport they failed on.
 */
final readonly class FailedMessages
{
    // A message that keeps the stamp telling it went to the failure transport
    // would not go there again if it fails anew: Messenger would drop it
    private const array DROPPED_STAMPS = [
        SentToFailureTransportStamp::class,
        DelayStamp::class,
        TransportMessageIdStamp::class,
    ];

    /**
     * @param ContainerInterface $transports the Messenger transports by name
     */
    public function __construct(
        private ContainerInterface $transports,
    ) {}

    /**
     * @throws FailedMessageException when the transport cannot find its messages
     */
    public function get(string $failureTransport, string $id): ?FailedMessage
    {
        $envelope = $this->receiver($failureTransport)->find($id);
        if (null === $envelope) {
            return null;
        }

        $description = QueueInspector::describe($envelope);
        $export = MessageJson::export($envelope->getMessage());

        return new FailedMessage(
            $id,
            $description['class'],
            $description['from'],
            $description['failed_at'],
            $description['error'],
            $export['json'] ?? null,
            $export['kept'] ?? [],
        );
    }

    /**
     * Sends a failed message back to the transport it failed on, and removes
     * it from the failure transport.
     *
     * @param string|null $json the properties to send the message with, null to send it as is
     *
     * @return string the transport the message was sent to
     *
     * @throws FailedMessageException when the message cannot be sent back
     */
    public function resend(string $failureTransport, string $id, ?string $json = null): string
    {
        $receiver = $this->receiver($failureTransport);
        $envelope = $receiver->find($id);
        if (null === $envelope) {
            throw new FailedMessageException(sprintf(
                'The message #%s is not in "%s" anymore.',
                $id,
                $failureTransport,
            ));
        }

        $transport = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName();
        if (null === $transport) {
            throw new FailedMessageException(sprintf(
                'The message #%s does not tell which transport it failed on.',
                $id,
            ));
        }

        $sender = $this->transports->has($transport) ? $this->transports->get($transport) : null;
        if (!$sender instanceof SenderInterface) {
            throw new FailedMessageException(sprintf('Messages cannot be sent to "%s".', $transport));
        }

        $message = null === $json ? $envelope->getMessage() : MessageJson::import($envelope->getMessage(), $json);

        // Sent first: the message stays in the failure transport when it cannot be sent
        $sender->send(new Envelope($message, self::stamps($envelope)));
        $receiver->reject($envelope);

        return $transport;
    }

    private function receiver(string $transport): ListableReceiverInterface
    {
        $receiver = $this->transports->has($transport) ? $this->transports->get($transport) : null;
        if (!$receiver instanceof ListableReceiverInterface) {
            throw new FailedMessageException(sprintf('The "%s" transport cannot list its messages.', $transport));
        }

        return $receiver;
    }

    /**
     * The stamps to send the message with, like retries and errors so far.
     *
     * @return list<StampInterface>
     */
    private static function stamps(Envelope $envelope): array
    {
        $stamps = [];
        foreach ($envelope->all() as $class => $classStamps) {
            if (in_array($class, self::DROPPED_STAMPS, true) || is_a($class, NonSendableStampInterface::class, true)) {
                continue;
            }

            array_push($stamps, ...$classStamps);
        }

        return $stamps;
    }
}
