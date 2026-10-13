<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\Process;

/**
 * Builds the process of a worker consuming one transport.
 */
interface ProcessFactory
{
    public function create(string $transport, int $workerId): Process;
}
