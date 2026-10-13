<?php

declare(strict_types=1);

/*
 * Stands for "messenger:consume": reports a few messages, then waits for
 * SIGTERM. Usage: php fake-worker.php <transport> <worker id> <messages> [mode]
 *
 * Modes:
 * - default: one message at a time
 * - interleaved: every message is received before the first one is handled
 * - slow-boot: takes a second before handling signals, like a kernel booting
 * - stubborn: ignores SIGTERM
 * - crash: exits with an error, like the console does
 */

use Welcomattic\MessengerCentral\Worker\WorkerReport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $transport, $worker, $messages, $mode] = $argv + [null, 'async', '1', '3', 'default'];

if ('slow-boot' === $mode) {
    sleep(1);
}

$stop = false;
pcntl_async_signals(true);
pcntl_signal(
    SIGTERM,
    'stubborn' === $mode
        ? SIG_IGN
        : static function () use (&$stop): void {
            $stop = true;
        },
);

/**
 * @param array<string, mixed> $payload
 */
function report(string $worker, string $type, array $payload = []): void
{
    $payload['worker'] = $worker;
    $payload['memory'] = 2 * 1024 * 1024;
    $payload['peak_memory'] = 4 * 1024 * 1024;

    echo WorkerReport::encode($type, $payload);
}

if ('crash' === $mode) {
    fwrite(
        STDERR,
        "\nIn Connection.php line 42:\n\n  Connection refused  \n\n\nmessenger:consume [-l|--limit LIMIT] [--] [<receivers>...]\n\n",
    );

    exit(1);
}

echo "[OK] Consuming messages from transport \"{$transport}\".\n";
report($worker, WorkerReport::STARTED, ['pid' => getmypid()]);

$received = [];
for ($i = 1; $i <= (int) $messages; ++$i) {
    $message = [
        'transport' => $transport,
        'class' => 'App\Message\Ping',
        'id' => (string) $i,
        'retry' => 0,
        'seq' => $i,
    ];
    report($worker, WorkerReport::RECEIVED, $message + ['at' => microtime(true)]);
    $received[] = $message;
    if ('interleaved' === $mode) {
        continue;
    }

    $failed = 0 === ($i % 3);
    report(
        $worker,
        $failed ? WorkerReport::FAILED : WorkerReport::HANDLED,
        $message
        + ['duration_ms' => 1.5]
        + ($failed ? ['will_retry' => false, 'error' => 'RuntimeException: boom'] : []),
    );
}

fwrite(STDERR, "a log line\n");

$deadline = microtime(true) + 10;
while (!$stop && microtime(true) < $deadline) {
    usleep(10_000);
}

// Interleaved messages are handled when the worker stops, the most recent first
foreach (array_reverse('interleaved' === $mode ? $received : []) as $message) {
    report($worker, WorkerReport::HANDLED, $message + ['duration_ms' => 1.5]);
}

report($worker, WorkerReport::STOPPED);
