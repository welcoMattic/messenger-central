<?php

declare(strict_types=1);

/*
 * Stands for "messenger:central:probe": answers every request with 42 waiting
 * messages. Usage: php fake-probe.php [mode]
 *
 * Modes:
 * - default: answers at once
 * - slow: takes a second to answer, like a broker that does not answer
 * - crash: exits with an error on the first request
 */

[, $mode] = $argv + [null, 'default'];

while (false !== ($line = fgets(STDIN))) {
    $request = json_decode($line, true);
    if (!is_array($request)) {
        continue;
    }
    if ('crash' === $mode) {
        fwrite(STDERR, "\nIn Connection.php line 42:\n\n  Connection refused  \n\n");

        exit(1);
    }

    if ('slow' === $mode) {
        sleep(1);
    }

    echo
        json_encode([
            'transport' => $request['transport'],
            'snapshot' => ['at' => microtime(true), 'count' => 42, 'messages' => [], 'error' => null],
        ]),
        "\n";
}
