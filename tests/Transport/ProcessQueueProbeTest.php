<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tests\Worker\FakeProcessFactory;
use Welcomattic\MessengerCentral\Transport\ProcessQueueProbe;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;

use function microtime;
use function usleep;

final class ProcessQueueProbeTest extends TestCase
{
    private FakeProcessFactory $factory;
    private ProcessQueueProbe $probe;

    protected function setUp(): void
    {
        $this->factory = new FakeProcessFactory();
        $this->probe = new ProcessQueueProbe($this->factory);
    }

    protected function tearDown(): void
    {
        $this->probe->stop();
    }

    public function testItInspectsTheQueuesInAChildProcess(): void
    {
        // When
        $this->probe->request('async', 5);
        $snapshot = $this->answer('async');

        // Then
        static::assertSame(42, $snapshot->count);
        static::assertNull($snapshot->error);
        static::assertFalse($this->probe->pending('async'));
        static::assertSame(1, $this->factory->probes);
    }

    public function testItDoesNotWaitForTheProbe(): void
    {
        // Given: a probe that waits for a broker
        $this->factory->probeMode = 'slow';

        // When
        $startedAt = microtime(true);
        $this->probe->request('async', 0);
        $this->probe->request('async', 0);
        $answers = $this->probe->poll();

        // Then
        static::assertLessThan(0.5, microtime(true) - $startedAt);
        static::assertSame([], $answers);
        static::assertTrue($this->probe->pending('async'));
        static::assertSame(42, $this->answer('async')->count);
        static::assertSame([], $this->probe->poll(), 'Asked once');
    }

    public function testItTellsWhyTheProbeExited(): void
    {
        // Given
        $this->factory->probeMode = 'crash';

        // When
        $this->probe->request('async', 0);
        $snapshot = $this->answer('async');
        $this->probe->request('failed', 0);
        $answers = $this->probe->poll();

        // Then
        static::assertSame('The queue probe exited: Connection refused (Connection.php line 42).', $snapshot->error);
        static::assertCount(1, $answers);
        static::assertSame('failed', $answers[0]['transport']);
        static::assertSame(
            'The queue probe exited: Connection refused (Connection.php line 42).',
            $answers[0]['snapshot']->error,
        );
        static::assertSame(1, $this->factory->probes, 'Started again after a delay');
    }

    private function answer(string $transport): QueueSnapshot
    {
        $deadline = microtime(true) + 5;
        do {
            foreach ($this->probe->poll() as $answer) {
                if ($transport === $answer['transport']) {
                    return $answer['snapshot'];
                }
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        static::fail('The probe did not answer.');
    }
}
