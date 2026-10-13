<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use Symfony\Component\Process\Process;
use Welcomattic\MessengerCentral\Worker\ProcessFactory;

use const PHP_BINARY;

final class FakeProcessFactory implements ProcessFactory
{
    /** @var list<array{string, int, int}> transport, worker id and concurrency of each worker */
    public array $created = [];

    /**
     * @param string $mode see tests/Fixtures/fake-worker.php
     */
    public function __construct(
        private readonly int $messages = 3,
        public string $mode = 'default',
    ) {}

    public function create(string $transport, int $workerId, int $concurrency = 1): Process
    {
        $this->created[] = [$transport, $workerId, $concurrency];

        return new Process([
            PHP_BINARY,
            __DIR__ . '/../Fixtures/fake-worker.php',
            $transport,
            (string) $workerId,
            (string) $this->messages,
            $this->mode,
        ]);
    }
}
