<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\Process;

/**
 * Builds the process of a worker consuming one transport.
 */
interface ProcessFactory
{
    /**
     * @param int $concurrency messages handled at the same time by the worker, needs Symfony 8.2 above 1
     */
    public function create(string $transport, int $workerId, int $concurrency = 1): Process;
}
