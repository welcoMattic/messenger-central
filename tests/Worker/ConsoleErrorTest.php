<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Worker\ConsoleError;

final class ConsoleErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function outputs(): iterable
    {
        yield 'nothing' => [[], null];

        yield 'console exception, root cause last' => [
            [
                '[OK] Consuming messages from transport "async".',
                'In AbstractConnection.php line 12:',
                '  [Doctrine\DBAL\Exception\ConnectionException]  ',
                '  An exception occurred in the driver:  ',
                'In Exception.php line 28:',
                '  SQLSTATE[08006] could not connect   ',
                '  to server  ',
                'messenger:consume [-l|--limit LIMIT] [--] [<receivers>...]',
            ],
            'SQLSTATE[08006] could not connect to server (Exception.php line 28)',
        ];

        yield 'PHP fatal error' => [
            ['PHP Fatal error:  Allowed memory size exhausted in /app/src/Handler.php on line 9', '#0 {main}'],
            'PHP Fatal error: Allowed memory size exhausted in /app/src/Handler.php on line 9',
        ];

        yield 'anything else' => [['a log line', 'the last one  '], 'the last one'];
    }

    /**
     * @param list<string> $lines
     */
    #[DataProvider('outputs')]
    public function testItFindsTheError(array $lines, ?string $expected): void
    {
        static::assertSame($expected, ConsoleError::summarize($lines));
    }
}
