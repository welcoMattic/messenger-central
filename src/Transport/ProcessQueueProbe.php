<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use JsonException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Welcomattic\MessengerCentral\Worker\ConsoleError;
use Welcomattic\MessengerCentral\Worker\ProcessFactory;

use function array_pop;
use function array_search;
use function array_slice;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function min;
use function rtrim;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Inspects the queues in a child process ("messenger:central:probe"), so
 * that a broker that does not answer never freezes the dashboard.
 *
 * Requests are written on the standard input of the probe, one JSON line
 * each, and answered in the same order on its standard output.
 */
final class ProcessQueueProbe implements QueueProbe
{
    private const float MAX_RESTART_DELAY = 30.0;
    private const int MAX_ERROR_LINES = 50;

    private ?Process $process = null;
    private ?InputStream $input = null;
    private string $buffer = '';

    /** @var list<string> errors written by the probe, most recent last */
    private array $errors = [];

    /** @var list<string> transports asked to the running probe, in order */
    private array $asked = [];

    /** @var list<array{transport: string, snapshot: QueueSnapshot}> */
    private array $answers = [];

    // Restarts of the probe when it exits, less and less often
    private int $failures = 0;
    private float $restartAt = 0.0;
    private ?string $lastError = null;

    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    public function request(string $transport, int $limit): void
    {
        if ($this->pending($transport)) {
            return;
        }

        $input = $this->input();
        if (null === $input) {
            $this->answer($transport, sprintf(
                'The queue probe exited%s.',
                null === $this->lastError ? '' : ': ' . $this->lastError,
            ));

            return;
        }

        $this->asked[] = $transport;
        $input->write(json_encode(['transport' => $transport, 'limit' => $limit], JSON_THROW_ON_ERROR) . "\n");
    }

    public function pending(string $transport): bool
    {
        return in_array($transport, $this->asked, true);
    }

    public function poll(): array
    {
        $process = $this->process;
        if (null !== $process) {
            $this->read($process);
        }

        $answers = $this->answers;
        $this->answers = [];

        return $answers;
    }

    public function stop(): void
    {
        $this->input?->close();
        if (null !== $this->process && $this->process->isRunning()) {
            $this->process->stop(1.0);
        }

        $this->process = null;
        $this->input = null;
    }

    /**
     * The input of the running probe, started if needed.
     */
    private function input(): ?InputStream
    {
        if (null !== $this->input) {
            return $this->input;
        }

        if ($this->now() < $this->restartAt) {
            return null;
        }

        $input = new InputStream();
        $process = $this->processFactory->createProbe();
        $process->setInput($input);
        try {
            $process->start();
        } catch (ExceptionInterface $error) {
            $this->exited($error->getMessage());

            return null;
        }

        $this->process = $process;
        $this->input = $input;
        $this->buffer = '';
        $this->errors = [];

        return $input;
    }

    private function read(Process $process): void
    {
        // Reads the pipes, all of them once the process exited
        $running = $process->isRunning();
        $output = $process->getIncrementalOutput();
        $process->clearOutput();
        $errorOutput = $process->getIncrementalErrorOutput();
        $process->clearErrorOutput();

        foreach (explode("\n", $errorOutput) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $this->errors[] = rtrim($line, "\r");
        }

        $this->errors = array_slice($this->errors, -self::MAX_ERROR_LINES);

        $lines = explode("\n", $this->buffer . $output);
        $this->buffer = array_pop($lines);
        foreach ($lines as $line) {
            $this->receive($line);
        }

        if ($running) {
            return;
        }

        $this->process = null;
        $this->input = null;
        $this->exited(
            ConsoleError::summarize($this->errors) ?? sprintf('exit code %s', $process->getExitCode() ?? '?'),
        );
    }

    private function receive(string $line): void
    {
        try {
            $answer = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not an answer, like a deprecation notice
            return;
        }

        if (!is_array($answer) || !is_string($answer['transport'] ?? null) || !is_array($answer['snapshot'] ?? null)) {
            return;
        }

        $transport = $answer['transport'];
        $index = array_search($transport, $this->asked, true);
        if (false === $index) {
            return;
        }

        unset($this->asked[$index]);
        $this->asked = array_values($this->asked);
        $this->failures = 0;
        $this->answers[] = ['transport' => $transport, 'snapshot' => QueueSnapshot::fromArray($answer['snapshot'])];
    }

    /**
     * Answers what the probe was asked with the reason it exited, and plans to start it again.
     */
    private function exited(string $reason): void
    {
        $this->lastError = $reason;
        foreach ($this->asked as $transport) {
            $this->answer($transport, sprintf('The queue probe exited: %s.', $reason));
        }

        $this->asked = [];
        ++$this->failures;
        $this->restartAt = $this->now() + min(self::MAX_RESTART_DELAY, 2 ** ($this->failures - 1));
    }

    private function answer(string $transport, string $error): void
    {
        $this->answers[] = ['transport' => $transport, 'snapshot' => new QueueSnapshot($this->now(), error: $error)];
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ($now->getMicrosecond() / 1_000_000);
    }
}
