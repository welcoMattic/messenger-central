<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use Symfony\Component\Process\Process;
use Welcomattic\MessengerCentral\Worker\ProcessFactory;

use const PHP_BINARY;

final class FakeProcessFactory implements ProcessFactory
{
    /** @var list<array{string, int}> */
    public array $created = [];

    public function __construct(
        private readonly int $messages = 3,
    ) {}

    public function create(string $transport, int $workerId): Process
    {
        $this->created[] = [$transport, $workerId];

        return new Process([
            PHP_BINARY,
            __DIR__ . '/../Fixtures/fake-worker.php',
            $transport,
            (string) $workerId,
            (string) $this->messages,
        ]);
    }
}
