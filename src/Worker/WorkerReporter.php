<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

use function fflush;
use function fopen;
use function fwrite;
use function getmypid;
use function hrtime;
use function intdiv;
use function is_resource;
use function mb_substr;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function preg_replace;
use function round;

/**
 * Reports what a worker does to the dashboard that started it.
 *
 * The listener is always registered but stays silent unless the worker was
 * spawned by the dashboard, which sets the MESSENGER_CENTRAL_WORKER
 * environment variable.
 */
final class WorkerReporter implements EventSubscriberInterface
{
    public const string ENV_VAR = 'MESSENGER_CENTRAL_WORKER';

    private const int MAX_ERROR_LENGTH = 300;

    /** @var resource|null */
    private $stream;

    private ?int $receivedAt = null;
    private int $lastHeartbeatAt = 0;

    /**
     * @param resource|null $stream defaults to the standard output
     */
    public function __construct(
        private readonly ?string $workerId,
        $stream = null,
        private readonly int $heartbeatIntervalMs = 1000,
    ) {
        $this->stream = $stream;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            // Run last, so that other listeners had their say on the message
            WorkerMessageReceivedEvent::class => ['onMessageReceived', -1024],
            WorkerMessageHandledEvent::class => ['onMessageHandled', -1024],
            WorkerMessageFailedEvent::class => ['onMessageFailed', -1024],
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->report(WorkerReport::STARTED, [
            'pid' => getmypid(),
            'transports' => $event->getWorker()->getMetadata()->getTransportNames(),
        ] + $this->memory());
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$event->shouldHandle()) {
            return;
        }

        $this->receivedAt = self::nanoseconds();
        $this->report(
            WorkerReport::RECEIVED,
            $this->describe($event->getEnvelope(), $event->getReceiverName()) + ['at' => microtime(true)],
        );
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->report(
            WorkerReport::HANDLED,
            $this->describe($event->getEnvelope(), $event->getReceiverName()) + $this->duration() + $this->memory(),
        );
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $error = $event->getThrowable();
        if ($error instanceof HandlerFailedException) {
            $error = $error->getPrevious() ?? $error;
        }

        $this->report(
            WorkerReport::FAILED,
            $this->describe($event->getEnvelope(), $event->getReceiverName())
            + [
                'will_retry' => $event->willRetry(),
                'error' => $error::class . ': ' . $this->singleLine($error),
            ]
            + $this->duration()
            + $this->memory(),
        );
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (null === $this->workerId) {
            return;
        }

        $now = intdiv(self::nanoseconds(), 1_000_000);
        if (($now - $this->lastHeartbeatAt) < $this->heartbeatIntervalMs) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $this->report(WorkerReport::HEARTBEAT, ['idle' => $event->isWorkerIdle()] + $this->memory());
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->report(WorkerReport::STOPPED, $this->memory());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function report(string $type, array $payload): void
    {
        if (null === $this->workerId) {
            return;
        }

        if (!is_resource($this->stream)) {
            $stream = fopen('php://stdout', 'w');
            if (false === $stream) {
                return;
            }

            $this->stream = $stream;
        }

        fwrite($this->stream, WorkerReport::encode($type, ['worker' => $this->workerId] + $payload));
        fflush($this->stream);
    }

    /**
     * @return array{transport: string, class: class-string, id: string|null, retry: int}
     */
    private function describe(Envelope $envelope, string $transport): array
    {
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();

        return [
            'transport' => $transport,
            'class' => $envelope->getMessage()::class,
            'id' => null === $id ? null : (string) $id,
            'retry' => $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0,
        ];
    }

    /**
     * @return array{duration_ms?: float}
     */
    private function duration(): array
    {
        if (null === $this->receivedAt) {
            return [];
        }

        $duration = round((self::nanoseconds() - $this->receivedAt) / 1_000_000, 2);
        $this->receivedAt = null;

        return ['duration_ms' => $duration];
    }

    /**
     * @return array{memory: int, peak_memory: int}
     */
    private function memory(): array
    {
        return ['memory' => memory_get_usage(true), 'peak_memory' => memory_get_peak_usage(true)];
    }

    private static function nanoseconds(): int
    {
        return (int) hrtime(true);
    }

    private function singleLine(Throwable $error): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $error->getMessage()), 0, self::MAX_ERROR_LENGTH);
    }
}
