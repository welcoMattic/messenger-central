<?php

declare(strict_types=1);

/*
 * Stands for "messenger:consume": reports a few messages, then waits for
 * SIGTERM. Usage: php fake-worker.php <transport> <worker id> <messages>
 */

use Welcomattic\MessengerCentral\Worker\WorkerReport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $transport, $worker, $messages] = $argv + [null, 'async', '1', '3'];

$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stop): void {
    $stop = true;
});

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

echo "[OK] Consuming messages from transport \"{$transport}\".\n";
report($worker, WorkerReport::STARTED, ['pid' => getmypid(), 'transports' => [$transport]]);

for ($i = 1; $i <= (int) $messages; ++$i) {
    $message = ['transport' => $transport, 'class' => 'App\Message\Ping', 'id' => (string) $i, 'retry' => 0];
    report($worker, WorkerReport::RECEIVED, $message);
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

report($worker, WorkerReport::STOPPED);
