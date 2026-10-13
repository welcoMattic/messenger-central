<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
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
use WeakMap;

use function count;
use function fflush;
use function fopen;
use function fwrite;
use function getmypid;
use function is_resource;
use function mb_substr;
use function memory_get_peak_usage;
use function memory_get_usage;
use function preg_replace;
use function restore_error_handler;
use function round;
use function set_error_handler;

/**
 * Reports what a worker does to the dashboard that started it.
 *
 * The listener is always registered but stays silent unless the worker was
 * spawned by the dashboard, which sets the MESSENGER_CENTRAL_WORKER
 * environment variable.
 *
 * Messages are tracked one by one: with "--concurrency" (Symfony 8.2) or
 * batch handlers, several of them are in flight at the same time.
 */
final class WorkerReporter implements EventSubscriberInterface
{
    public const string ENV_VAR = 'MESSENGER_CENTRAL_WORKER';

    private const int MAX_ERROR_LENGTH = 300;

    /** @var resource|null */
    private $stream;

    /** @var WeakMap<object, array{seq: int, at: float}> messages received and not handled yet */
    private WeakMap $inFlight;

    private int $sequence = 0;
    private float $lastHeartbeatAt = 0.0;

    // The dashboard went away: nobody reads the reports anymore
    private bool $orphaned = false;

    /**
     * @param resource|null $stream defaults to the standard output
     */
    public function __construct(
        private readonly ?string $workerId,
        $stream = null,
        private readonly int $heartbeatIntervalMs = 1000,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->stream = $stream;
        $this->inFlight = new WeakMap();
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
        $this->report(WorkerReport::STARTED, ['pid' => getmypid()] + $this->memory());
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (null === $this->workerId || !$event->shouldHandle()) {
            return;
        }

        $received = ['seq' => ++$this->sequence, 'at' => $this->now()];
        $this->inFlight[$event->getEnvelope()->getMessage()] = $received;
        $this->report(
            WorkerReport::RECEIVED,
            $this->describe($event->getEnvelope(), $event->getReceiverName()) + $received,
        );
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->report(
            WorkerReport::HANDLED,
            $this->describe($event->getEnvelope(), $event->getReceiverName())
            + $this->finish($event->getEnvelope())
            + $this->memory(),
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
            + $this->finish($event->getEnvelope())
            + $this->memory(),
        );
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (null === $this->workerId) {
            return;
        }

        $now = $this->now();
        if (!$this->orphaned && (($now - $this->lastHeartbeatAt) * 1000) >= $this->heartbeatIntervalMs) {
            $this->lastHeartbeatAt = $now;
            $this->report(WorkerReport::HEARTBEAT, ['in_flight' => count($this->inFlight)] + $this->memory());
        }

        if ($this->orphaned) {
            // Without the dashboard, nothing would ever stop this worker
            $event->getWorker()->stop();
        }
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
        if (null === $this->workerId || $this->orphaned) {
            return;
        }

        if (!is_resource($this->stream)) {
            $stream = fopen('php://stdout', 'w');
            if (false === $stream) {
                return;
            }

            $this->stream = $stream;
        }

        $line = WorkerReport::encode($type, ['worker' => $this->workerId] + $payload);

        // Writing to the pipe of a dashboard that went away raises a "broken
        // pipe" notice, that the error handler of the application may throw
        set_error_handler(static fn(): bool => true);
        try {
            $written = false !== fwrite($this->stream, $line) && fflush($this->stream);
        } finally {
            restore_error_handler();
        }

        if (!$written) {
            $this->orphaned = true;
        }
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
     * @return array{seq?: int, duration_ms?: float}
     */
    private function finish(Envelope $envelope): array
    {
        // Symfony keeps the message object of the received envelope, even when
        // another process handled it
        $message = $envelope->getMessage();
        $received = $this->inFlight[$message] ?? null;
        if (null === $received) {
            return [];
        }

        unset($this->inFlight[$message]);

        return ['seq' => $received['seq'], 'duration_ms' => round(($this->now() - $received['at']) * 1000, 2)];
    }

    /**
     * @return array{memory: int, peak_memory: int}
     */
    private function memory(): array
    {
        return ['memory' => memory_get_usage(true), 'peak_memory' => memory_get_peak_usage(true)];
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ($now->getMicrosecond() / 1_000_000);
    }

    private function singleLine(Throwable $error): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $error->getMessage()), 0, self::MAX_ERROR_LENGTH);
    }
}
