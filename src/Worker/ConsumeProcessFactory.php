<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use const PHP_BINARY;

/**
 * Starts "messenger:consume" through the application console.
 */
final readonly class ConsumeProcessFactory implements ProcessFactory
{
    /**
     * @param list<string> $consumeOptions extra options given to messenger:consume
     */
    public function __construct(
        private string $consolePath,
        private string $environment,
        private array $consumeOptions = [],
        private ?string $phpBinary = null,
    ) {}

    public function create(string $transport, int $workerId): Process
    {
        $php = $this->phpBinary ?? new PhpExecutableFinder()->find(false);

        $process = new Process([
            false === $php ? PHP_BINARY : $php,
            $this->consolePath,
            'messenger:consume',
            $transport,
            '--no-interaction',
            ...$this->consumeOptions,
        ], env: [
            WorkerReporter::ENV_VAR => (string) $workerId,
            // Gives every worker its own consumer name, as Symfony recommends for Redis
            'MESSENGER_CONSUMER_NAME' => 'messenger-central-' . $workerId,
            'APP_ENV' => $this->environment,
        ]);
        $process->setTimeout(null);

        return $process;
    }
}
