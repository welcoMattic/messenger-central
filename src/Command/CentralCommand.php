<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Terminal\Terminal;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Transport\QueueProbe;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Tui\Dashboard;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function stream_isatty;

use const PHP_OS_FAMILY;
use const STDIN;
use const STDOUT;

#[AsCommand(name: 'messenger:central', description: 'Drive the Messenger workers from a terminal UI')]
final readonly class CentralCommand
{
    public function __construct(
        private TransportCatalog $catalog,
        private WorkerSupervisor $supervisor,
        private QueueProbe $probe,
        private FailedMessages $failedMessages,
        private int $concurrency = 1,
        private bool $debug = true,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Run even when the kernel runs without debug, like in production')]
        bool $force = false,
    ): int {
        if (!$this->debug && !$force) {
            $io->error([
                'Messenger Central is a development tool, and the kernel runs without debug, like in production.',
                'Run it in the dev environment, or use --force to run it anyway.',
            ]);

            return Command::FAILURE;
        }

        if ('Windows' === PHP_OS_FAMILY) {
            $io->error('Messenger Central needs a POSIX terminal: run it on Linux, macOS or WSL.');

            return Command::FAILURE;
        }

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
                $this->probe,
                $this->failedMessages,
                new Terminal(),
                concurrency: $this->concurrency,
            )->run();
        } finally {
            $this->probe->stop();
            if ($this->supervisor->hasRunning()) {
                $io->writeln('Stopping the workers…');
                $this->supervisor->stopAll();
            }
        }

        return Command::SUCCESS;
    }
}
