<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Functional;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Welcomattic\MessengerCentral\Tests\Fixtures\App\FileTransport;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Worker\ConsumeProcessFactory;
use Welcomattic\MessengerCentral\Worker\ParallelExecution;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function microtime;
use function putenv;
use function sys_get_temp_dir;
use function uniqid;
use function usleep;

/**
 * Starts real "messenger:consume" workers on the test application.
 */
#[RequiresPhpExtension('pcntl')]
final class RealWorkerTest extends TestCase
{
    private const string CONSOLE = __DIR__ . '/../Fixtures/App/bin/console';

    private string $directory;
    private FileTransport $transport;
    private WorkerSupervisor $supervisor;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/messenger-central-tests/' . uniqid('files-', true);
        new Filesystem()->mkdir($this->directory);
        // Process passes the variables that $_SERVER or $_ENV holds
        putenv('MESSENGER_CENTRAL_TEST_DIR=' . $this->directory);
        $_ENV['MESSENGER_CENTRAL_TEST_DIR'] = $this->directory;

        $this->transport = new FileTransport($this->directory, new PhpSerializer());
        $this->supervisor = new WorkerSupervisor(new ConsumeProcessFactory(self::CONSOLE, 'test', ['--time-limit=60']));
    }

    protected function tearDown(): void
    {
        $this->supervisor->stopAll(5.0);
        putenv('MESSENGER_CENTRAL_TEST_DIR');
        unset($_ENV['MESSENGER_CENTRAL_TEST_DIR']);
        new Filesystem()->remove($this->directory);
    }

    public function testAWorkerReportsTheMessagesItHandles(): void
    {
        // Given
        $this->send(3);

        // When
        $this->supervisor->scale('files', 1);
        $this->waitForProcessed(3);

        // Then
        $worker = $this->supervisor->workers('files')[0];
        static::assertSame(WorkerStatus::Idle, $worker->status);
        static::assertNotNull($worker->pid);
        static::assertSame(3, $worker->processed);
        static::assertSame(RoutedByClass::class, $worker->last['class'] ?? null);
        static::assertSame(0, $this->transport->getMessageCount());

        // When
        $this->supervisor->stop('files');
        $this->waitUntil(fn(): bool => !$this->supervisor->hasRunning());

        // Then
        static::assertTrue($worker->exitedCleanly());
    }

    public function testAWorkerHandlesSeveralMessagesAtATime(): void
    {
        if (!ParallelExecution::isAvailable()) {
            static::markTestSkipped('Handling several messages at a time needs Symfony 8.2 and amphp/parallel.');
        }

        // Given
        $this->send(4);

        // When
        $this->supervisor->scale('files', 1, 2);
        $this->waitForProcessed(4);

        // Then
        $worker = $this->supervisor->workers('files')[0];
        static::assertSame(2, $worker->concurrency);
        static::assertSame(4, $worker->processed);
        static::assertSame([], $worker->inFlight);
    }

    private function send(int $messages): void
    {
        for ($i = 1; $i <= $messages; ++$i) {
            $this->transport->send(new Envelope(new RoutedByClass('message ' . $i)));
        }
    }

    private function waitForProcessed(int $messages): void
    {
        $this->waitUntil(fn(): bool => $this->supervisor->activity('files')->processed >= $messages);
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitUntil(\Closure $condition, float $timeout = 30.0): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $this->supervisor->poll();
            if ($condition()) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $output = [];
        foreach ($this->supervisor->workers('files') as $worker) {
            $output = [...$output, ...$worker->output];
        }

        static::fail("The condition was not met in time. Output of the workers:\n" . implode("\n", $output));
    }
}
