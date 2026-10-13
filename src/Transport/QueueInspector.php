<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

use function mb_substr;
use function preg_replace;

/**
 * Looks at the messages stored in a transport, when it can tell.
 */
final readonly class QueueInspector
{
    private const int MAX_ERROR_LENGTH = 300;

    public function __construct(
        private ContainerInterface $receivers,
        private ClockInterface $clock = new NativeClock(),
    ) {}

    public function inspect(string $transport, int $limit = 0): QueueSnapshot
    {
        $date = $this->clock->now();
        $now = $date->getTimestamp() + ($date->getMicrosecond() / 1_000_000);
        if (!$this->receivers->has($transport)) {
            return new QueueSnapshot($now);
        }

        try {
            $receiver = $this->receivers->get($transport);
            $count = $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null;

            $messages = null;
            if ($limit > 0 && $receiver instanceof ListableReceiverInterface) {
                $messages = [];
                foreach ($receiver->all($limit) as $envelope) {
                    $messages[] = self::describe($envelope);
                }
            }
        } catch (Throwable $error) {
            return new QueueSnapshot($now, error: self::singleLine($error->getMessage()));
        }

        return new QueueSnapshot($now, $count, $messages);
    }

    /**
     * @return array{class: string, id: string|null, from: string|null, failed_at: float|null, error: string|null}
     */
    public static function describe(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
        $failedAt = $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt();
        $error = $envelope->last(ErrorDetailsStamp::class);

        return [
            'class' => $message::class,
            'id' => null === $id ? null : (string) $id,
            // Messages of a failure transport tell where they come from
            'from' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            'failed_at' => null === $failedAt
                ? null
                : $failedAt->getTimestamp() + ($failedAt->getMicrosecond() / 1_000_000),
            'error' => match (true) {
                null !== $error => self::singleLine($error->getExceptionClass() . ': ' . $error->getExceptionMessage()),
                $message instanceof MessageDecodingFailedException => self::singleLine($message->getMessage()),
                default => null,
            },
        ];
    }

    private static function singleLine(string $text): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, self::MAX_ERROR_LENGTH);
    }
}
