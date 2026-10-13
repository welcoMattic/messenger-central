<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;
use Welcomattic\MessengerCentral\Command\ProbeCommand;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Tests\Tui\FakeReceiver;
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;

use function explode;
use function fopen;
use function fwrite;
use function json_decode;
use function rewind;
use function stream_get_contents;
use function trim;

final class ProbeCommandTest extends TestCase
{
    public function testItAnswersEveryRequestAndResetsTheServices(): void
    {
        // Given
        $resetter = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $command = new ProbeCommand(new QueueInspector(new ServiceLocator([
            'async' => static fn(): FakeReceiver => new FakeReceiver(),
        ])), $resetter);
        $input = self::stream("{\"transport\": \"async\", \"limit\": 1}\nnot a request\n{\"transport\": \"failed\"}\n");
        $output = self::stream('');

        // When
        $command->serve($input, $output);

        // Then
        rewind($output);
        $lines = explode("\n", trim((string) stream_get_contents($output)));
        static::assertCount(2, $lines);
        static::assertSame(2, $resetter->resets);

        $first = json_decode($lines[0], true);
        static::assertIsArray($first);
        static::assertSame('async', $first['transport']);
        static::assertIsArray($first['snapshot']);
        $snapshot = QueueSnapshot::fromArray($first['snapshot']);
        static::assertSame(42, $snapshot->count);
        static::assertSame(
            ['class' => RoutedByClass::class, 'id' => '41', 'from' => null, 'failed_at' => null, 'error' => null],
            $snapshot->messages[0] ?? null,
        );
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+');
        static::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
