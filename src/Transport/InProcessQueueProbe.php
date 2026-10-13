<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Inspects the queues in the current process, answering at once.
 */
final class InProcessQueueProbe implements QueueProbe
{
    /** @var list<array{transport: string, snapshot: QueueSnapshot}> */
    private array $answers = [];

    /**
     * @param ResetInterface|null $resetter resets the services after each inspection, like the
     *                                      profiler data of the queries, that would grow forever
     */
    public function __construct(
        private readonly QueueInspector $inspector,
        private readonly ?ResetInterface $resetter = null,
    ) {}

    public function request(string $transport, int $limit): void
    {
        $this->answers[] = ['transport' => $transport, 'snapshot' => $this->inspector->inspect($transport, $limit)];
        $this->resetter?->reset();
    }

    public function pending(string $transport): bool
    {
        return false;
    }

    public function poll(): array
    {
        $answers = $this->answers;
        $this->answers = [];

        return $answers;
    }

    public function stop(): void {}
}
