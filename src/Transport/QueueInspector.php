<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

use function microtime;
use function preg_replace;

/**
 * Looks at the messages stored in a transport, when it can tell.
 */
final readonly class QueueInspector
{
    public function __construct(
        private ContainerInterface $receivers,
    ) {}

    public function inspect(string $transport, int $limit = 0): QueueSnapshot
    {
        $now = microtime(true);
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
                    $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
                    $messages[] = [
                        'class' => $envelope->getMessage()::class,
                        'id' => null === $id ? null : (string) $id,
                    ];
                }
            }
        } catch (Throwable $error) {
            return new QueueSnapshot($now, error: (string) preg_replace('/\s+/', ' ', $error->getMessage()));
        }

        return new QueueSnapshot($now, $count, $messages);
    }
}
