<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\AsyncMessageInterface;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\AttributeMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\MultiTransportMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\OverriddenAttributeMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByInterface;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\SyncMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\Wild\WildcardMessage;
use Welcomattic\MessengerCentral\Transport\MessageRouter;

final class MessageRouterTest extends TestCase
{
    private const array ROUTING = [
        RoutedByClass::class => ['async'],
        AsyncMessageInterface::class => ['messenger.transport.async'],
        'Welcomattic\MessengerCentral\Tests\Fixtures\Message\Wild\*' => ['low'],
        OverriddenAttributeMessage::class => ['async'],
    ];

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideRoutes')]
    public function testItRoutesLikeMessenger(string $class, array $expected): void
    {
        // Given
        $router = new MessageRouter(self::ROUTING);

        // When
        $transports = $router->route($class);

        // Then
        static::assertSame($expected, $transports);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'class routing' => [RoutedByClass::class, ['async']];
        yield 'interface routing, service id normalized' => [RoutedByInterface::class, ['async']];
        yield 'namespace wildcard' => [WildcardMessage::class, ['low']];
        yield 'AsMessage attribute' => [AttributeMessage::class, ['high']];
        yield 'AsMessage attribute with several transports' => [MultiTransportMessage::class, ['high', 'low']];
        yield 'configuration wins over the attribute' => [OverriddenAttributeMessage::class, ['async']];
        yield 'not routed' => [SyncMessage::class, []];
        yield 'interface itself' => [AsyncMessageInterface::class, []];
        yield 'unknown class' => ['App\Message\DoesNotExist', []];
    }

    public function testCatchAllIsOnlyAFallback(): void
    {
        // Given
        $router = new MessageRouter(['*' => ['fallback'], RoutedByClass::class => ['async']]);

        // When / Then
        static::assertSame(['async'], $router->route(RoutedByClass::class));
        static::assertSame(['fallback'], $router->route(SyncMessage::class));
    }

    public function testItGroupsClassesByTransport(): void
    {
        // Given
        $router = new MessageRouter(self::ROUTING);

        // When
        $byTransport = $router->routeAll([
            RoutedByClass::class,
            RoutedByInterface::class,
            MultiTransportMessage::class,
            SyncMessage::class,
            RoutedByClass::class,
        ]);

        // Then
        static::assertSame(
            [
                'async' => [RoutedByClass::class, RoutedByInterface::class],
                'high' => [MultiTransportMessage::class],
                'low' => [MultiTransportMessage::class],
            ],
            $byTransport,
        );
    }
}
