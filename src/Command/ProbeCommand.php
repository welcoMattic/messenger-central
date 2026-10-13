<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Command;

use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Service\ResetInterface;
use Welcomattic\MessengerCentral\Transport\QueueInspector;

use function fflush;
use function fgets;
use function fwrite;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const STDIN;
use const STDOUT;

/**
 * Inspects queues for the dashboard, which starts it: one JSON request per
 * line on the standard input, one answer per line on the standard output.
 */
#[AsCommand(name: self::NAME, description: 'Inspects queues for messenger:central, which starts it', hidden: true)]
final readonly class ProbeCommand
{
    public const string NAME = 'messenger:central:probe';

    private const int JSON_FLAGS =
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param ResetInterface|null $resetter resets the services after each inspection, like the
     *                                      profiler data of the queries, that would grow forever
     */
    public function __construct(
        private QueueInspector $inspector,
        private ?ResetInterface $resetter = null,
    ) {}

    public function __invoke(): int
    {
        $this->serve(STDIN, STDOUT);

        return Command::SUCCESS;
    }

    /**
     * Answers requests until the input closes, when the dashboard quits.
     *
     * @param resource $input
     * @param resource $output
     */
    public function serve($input, $output): void
    {
        while (false !== ($line = fgets($input))) {
            try {
                $request = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($request) || !is_string($request['transport'] ?? null)) {
                continue;
            }

            $limit = $request['limit'] ?? 0;
            $snapshot = $this->inspector->inspect($request['transport'], is_int($limit) ? max(0, $limit) : 0);
            $answer = json_encode([
                'transport' => $request['transport'],
                'snapshot' => $snapshot->toArray(),
            ], self::JSON_FLAGS);
            fwrite($output, (string) $answer . "\n");
            fflush($output);

            $this->resetter?->reset();
        }
    }
}
