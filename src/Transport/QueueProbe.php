<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

/**
 * Inspects the queues of transports without blocking the dashboard: a broker
 * that does not answer can hold a connection until its timeout.
 */
interface QueueProbe
{
    /**
     * Asks for the messages waiting in a transport, answered by a later poll().
     *
     * @param int $limit how many messages to list, 0 to only count them
     */
    public function request(string $transport, int $limit): void;

    /**
     * Whether a request for the transport waits for its answer.
     */
    public function pending(string $transport): bool;

    /**
     * @return list<array{transport: string, snapshot: QueueSnapshot}> the answers received since the last call
     */
    public function poll(): array;

    public function stop(): void;
}
