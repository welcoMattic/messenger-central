<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Welcomattic\MessengerCentral\Command\ProbeCommand;

use function getmypid;
use function sprintf;

use const PHP_BINARY;

/**
 * Starts "messenger:consume", and the queue probe, through the application console.
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

    public function create(string $transport, int $workerId, int $concurrency = 1): Process
    {
        $dashboard = getmypid();

        $process = new Process([
            $this->php(),
            $this->consolePath,
            'messenger:consume',
            $transport,
            '--no-interaction',
            ...($concurrency > 1 ? ['--concurrency=' . $concurrency] : []),
            ...$this->consumeOptions,
        ], env: [
            WorkerReporter::ENV_VAR => (string) $workerId,
            // Gives every worker its own consumer name, as Symfony recommends for Redis, which
            // holds the id of the dashboard process: two dashboards may share a Redis server
            'MESSENGER_CONSUMER_NAME' => sprintf(
                'messenger-central-%d-%d',
                false === $dashboard ? 0 : $dashboard,
                $workerId,
            ),
            'APP_ENV' => $this->environment,
        ]);
        $process->setTimeout(null);

        return $process;
    }

    public function createProbe(): Process
    {
        $process = new Process([$this->php(), $this->consolePath, ProbeCommand::NAME, '--no-interaction'], env: [
            'APP_ENV' => $this->environment,
        ]);
        $process->setTimeout(null);

        return $process;
    }

    private function php(): string
    {
        $php = $this->phpBinary ?? new PhpExecutableFinder()->find(false);

        return false === $php ? PHP_BINARY : $php;
    }
}
