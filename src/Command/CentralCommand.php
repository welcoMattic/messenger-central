<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Terminal\Terminal;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Tui\Dashboard;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function stream_isatty;

use const STDIN;
use const STDOUT;

#[AsCommand(name: 'messenger:central', description: 'Drive the Messenger workers from a terminal UI')]
final readonly class CentralCommand
{
    public function __construct(
        private TransportCatalog $catalog,
        private WorkerSupervisor $supervisor,
        private QueueInspector $inspector,
        private FailedMessages $failedMessages,
        private int $concurrency = 1,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            $io->error('Messenger Central needs an interactive terminal.');

            return Command::FAILURE;
        }

        if ([] === $this->catalog->all()) {
            $io->warning('No Messenger transport is configured.');

            return Command::SUCCESS;
        }

        try {
            new Dashboard(
                $this->catalog,
                $this->supervisor,
                $this->inspector,
                $this->failedMessages,
                new Terminal(),
                concurrency: $this->concurrency,
            )->run();
        } finally {
            if ($this->supervisor->hasRunning()) {
                $io->writeln('Stopping the workers…');
                $this->supervisor->stopAll();
            }
        }

        return Command::SUCCESS;
    }
}
