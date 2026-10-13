<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Welcomattic\MessengerCentral\DependencyInjection\CollectTransportsPass;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;

final class CollectTransportsPassTest extends TestCase
{
    public function testItUnwrapsTheSerializersOfSymfony82(): void
    {
        // Given
        $container = new ContainerBuilder();
        $container->register(CollectTransportsPass::CATALOG_ID, TransportCatalog::class)->setArguments([[]]);
        $container->register('messenger.receiver_locator');
        $container->register('messenger.signing_serializer')->setAbstract(true);

        // What Symfony 8.2 builds for a transport with "sign: true" and a claim check
        $container->setDefinition('.messenger.transport.async.signing_serializer', new ChildDefinition(
            'messenger.signing_serializer',
        )
            ->replaceArgument(0, new Reference('app.serializer'))
            ->replaceArgument(2, ['*', 'App\Message\Signed']));
        $container->setDefinition('.messenger.transport.async.claim_check_serializer', new Definition()->setArguments([
            new Reference('.messenger.transport.async.signing_serializer'),
            null,
            1024,
        ]));
        $container->setDefinition('.messenger.transport.plain.signing_serializer', new ChildDefinition(
            'messenger.signing_serializer',
        )->replaceArgument(0, new Reference('messenger.default_serializer')));
        $this->transport($container, 'async', '.messenger.transport.async.claim_check_serializer', ['priority' => 5]);
        $this->transport($container, 'plain', '.messenger.transport.plain.signing_serializer');

        // When
        new CollectTransportsPass()->process($container);

        // Then
        $catalog = $container->getDefinition(CollectTransportsPass::CATALOG_ID)->getArgument(0);
        static::assertIsArray($catalog);
        static::assertSame('app.serializer', $catalog['async']['serializer'] ?? null);
        static::assertTrue($catalog['async']['signed'] ?? null);
        static::assertTrue($catalog['async']['claim_check'] ?? null);
        static::assertSame(5, $catalog['async']['priority'] ?? null);
        static::assertSame('messenger.default_serializer', $catalog['plain']['serializer'] ?? null);
        static::assertFalse($catalog['plain']['signed'] ?? null, 'Only the types that require it are signed');
        static::assertFalse($catalog['plain']['claim_check'] ?? null);
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function transport(ContainerBuilder $container, string $name, string $serializer, array $tag = []): void
    {
        $container
            ->register('messenger.transport.' . $name, TransportInterface::class)
            ->setFactory([new Reference('messenger.transport_factory'), 'createTransport'])
            ->setArguments(['in-memory://', ['transport_name' => $name], new Reference($serializer)])
            ->addTag('messenger.receiver', ['alias' => $name] + $tag);
    }
}
