<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;

final class TransportDefinitionTest extends TestCase
{
    #[DataProvider('provideDsns')]
    public function testItMasksCredentialsInTheDsn(string $dsn, string $expected): void
    {
        // Given
        $transport = new TransportDefinition('async', $dsn);

        // When
        $masked = $transport->maskedDsn();

        // Then
        static::assertSame($expected, $masked);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDsns(): iterable
    {
        yield 'no credentials' => ['doctrine://default?queue_name=async', 'doctrine://default?queue_name=async'];
        yield 'user and password' => [
            'amqp://guest:guest@rabbitmq:5672/%2f/messages',
            'amqp://guest:****@rabbitmq:5672/%2f/messages',
        ];
        yield 'password only' => ['redis://s3cr3t@localhost:6379/messages', 'redis://****@localhost:6379/messages'];
        yield 'secrets in the query' => [
            'sqs://localhost/queue?access_key=AKIA&secret_key=xyz&region=eu-west-3',
            'sqs://localhost/queue?access_key=****&secret_key=****&region=eu-west-3',
        ];
        yield 'keys that are not secrets' => [
            'amqp://localhost/%2f/messages?binding_keys[0]=orders',
            'amqp://localhost/%2f/messages?binding_keys[0]=orders',
        ];
        yield 'sync' => ['sync://', 'sync://'];
    }

    public function testItMasksSensitiveOptions(): void
    {
        // Given
        $transport = new TransportDefinition('async', options: [
            'queue_name' => 'async',
            'auth' => ['user', 'pass'],
            'sentinel' => ['auth' => 'pass', 'master' => 'mymaster'],
            'api_key' => null,
        ]);

        // When
        $options = $transport->maskedOptions();

        // Then
        static::assertSame(
            [
                'queue_name' => 'async',
                'auth' => '****',
                'sentinel' => ['auth' => '****', 'master' => 'mymaster'],
                'api_key' => null,
            ],
            $options,
        );
    }
}
