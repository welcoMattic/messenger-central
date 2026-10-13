<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use function class_exists;

/**
 * Tells whether "messenger:consume --concurrency" can run here.
 *
 * Checked at runtime and not when the container is compiled, so that
 * installing amphp/parallel is enough, without clearing the cache.
 */
final class ParallelExecution
{
    public static function isAvailable(): bool
    {
        return (
            // Symfony 8.2
            class_exists('Symfony\Component\Messenger\Execution\ParallelExecutionStrategy')
            && class_exists('Amp\Parallel\Worker\ContextWorkerFactory')
        );
    }
}
