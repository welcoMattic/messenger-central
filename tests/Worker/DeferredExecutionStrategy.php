<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Execution\MessageExecutionStrategyInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function array_reverse;
use function count;

/**
 * Handles messages by batches, the most recent first, like several
 * messages handled at the same time would finish.
 */
final class DeferredExecutionStrategy implements MessageExecutionStrategyInterface
{
    /** @var list<array{Envelope, string}> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly int $concurrency,
    ) {}

    public function execute(Envelope $envelope, string $transportName, callable $onHandled): void
    {
        $this->pending[] = [$envelope, $transportName];
        if (count($this->pending) >= $this->concurrency) {
            $this->complete($onHandled);
        }
    }

    public function shouldPauseConsumption(): bool
    {
        return count($this->pending) >= $this->concurrency;
    }

    // The timeout argument comes with Symfony 8.2
    public function wait(callable $onHandled, ?float $timeout = null): bool
    {
        return $this->complete($onHandled);
    }

    public function flush(callable $onHandled, bool|float $force = false): bool
    {
        // The worker flushes after each fetch, and forces it when it stops
        return true === $force && $this->complete($onHandled);
    }

    public function shutdown(): void {}

    private function complete(callable $onHandled): bool
    {
        $pending = array_reverse($this->pending);
        $this->pending = [];
        foreach ($pending as [$envelope, $transportName]) {
            $acked = false;
            try {
                $onHandled($this->bus->dispatch($envelope), $transportName, $acked, null);
            } catch (Throwable $error) {
                $onHandled($envelope, $transportName, $acked, $error);
            }
        }

        return [] !== $pending;
    }
}
