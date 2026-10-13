<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\Process;

/**
 * Builds the processes the dashboard starts: workers, and the probe that
 * inspects the queues.
 */
interface ProcessFactory
{
    /**
     * @param int $concurrency messages handled at the same time by the worker, needs Symfony 8.2 above 1
     */
    public function create(string $transport, int $workerId, int $concurrency = 1): Process;

    /**
     * The process of "messenger:central:probe", which answers queue inspections.
     */
    public function createProbe(): Process;
}
