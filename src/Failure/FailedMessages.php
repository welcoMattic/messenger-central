<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Failure;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Welcomattic\MessengerCentral\Transport\QueueInspector;

use function array_push;
use function in_array;
use function is_a;
use function max;
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

        $errors = [];
        $trace = null;
        foreach ($envelope->all(ErrorDetailsStamp::class) as $stamp) {
            $errors[] = $stamp->getExceptionClass() . ': ' . $stamp->getExceptionMessage();
            $trace = $stamp->getFlattenException()?->getTraceAsString() ?? $trace;
        }

        $retries = 0;
        foreach ($envelope->all(RedeliveryStamp::class) as $stamp) {
            $retries = max($retries, $stamp->getRetryCount());
        }

        return new FailedMessage(
            $id,
            $description['class'],
            $description['from'],
            $description['failed_at'],
            $description['error'],
            $export['json'] ?? null,
            $export['kept'] ?? [],
            $errors,
            $trace,
            $retries,
        );
    }

    /**
     * Removes a failed message for good.
     *
     * @return string the class of the message
     *
     * @throws FailedMessageException when the message is not there anymore
     */
    public function remove(string $failureTransport, string $id): string
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

        $receiver->reject($envelope);

        return $envelope->getMessage()::class;
    }

    /**
     * Sends every failed message back to the transport it failed on, as is.
     *
     * @return array{sent: int, kept: int} the messages sent back, and the ones kept as they do not tell
     *                                     which transport they failed on
     */
    public function resendAll(string $failureTransport): array
    {
        $receiver = $this->receiver($failureTransport);
        $sent = 0;
        $kept = 0;
        foreach (self::envelopes($receiver) as $envelope) {
            $sender = $this->sender($envelope);
            if (null === $sender) {
                ++$kept;

                continue;
            }

            $sender->send(new Envelope($envelope->getMessage(), self::stamps($envelope)));
            $receiver->reject($envelope);
            ++$sent;
        }

        return ['sent' => $sent, 'kept' => $kept];
    }

    /**
     * Removes every failed message for good.
     *
     * @return int the messages removed
     */
    public function removeAll(string $failureTransport): int
    {
        $receiver = $this->receiver($failureTransport);
        $removed = 0;
        foreach (self::envelopes($receiver) as $envelope) {
            $receiver->reject($envelope);
            ++$removed;
        }

        return $removed;
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

        $sender = $this->sender($envelope);
        if (null === $sender) {
            throw new FailedMessageException(sprintf('Messages cannot be sent to "%s".', $transport));
        }

        $message = null === $json ? $envelope->getMessage() : MessageJson::import($envelope->getMessage(), $json);

        // Sent first: the message stays in the failure transport when it cannot be sent
        $sender->send(new Envelope($message, self::stamps($envelope)));
        $receiver->reject($envelope);

        return $transport;
    }

    /**
     * The transport the message failed on, when it can send messages.
     */
    private function sender(Envelope $envelope): ?SenderInterface
    {
        $transport = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName();
        $sender = null !== $transport && $this->transports->has($transport) ? $this->transports->get($transport) : null;

        return $sender instanceof SenderInterface ? $sender : null;
    }

    /**
     * Every message of the transport, read before any of them is removed.
     *
     * @return list<Envelope>
     */
    private static function envelopes(ListableReceiverInterface $receiver): array
    {
        $envelopes = [];
        foreach ($receiver->all() as $envelope) {
            $envelopes[] = $envelope;
        }

        return $envelopes;
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
