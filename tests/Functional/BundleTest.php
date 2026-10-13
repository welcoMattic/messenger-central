<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Welcomattic\MessengerCentral\DependencyInjection\CollectTransportsPass;
use Welcomattic\MessengerCentral\Tests\Fixtures\App\TestKernel;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\AttributeMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;

use function array_keys;
use function putenv;
use function restore_exception_handler;
use function set_exception_handler;

final class BundleTest extends TestCase
{
    private const array MESSENGER = [
        'failure_transport' => 'failed',
        'transports' => [
            'async' => [
                'dsn' => 'in-memory://',
                'options' => ['serialize' => true],
                'retry_strategy' => ['max_retries' => 5, 'delay' => 500],
            ],
            'high' => '%env(MESSENGER_CENTRAL_TEST_DSN)%',
            'failed' => 'in-memory://',
            'sync' => 'sync://',
        ],
        'routing' => [
            RoutedByClass::class => 'async',
        ],
    ];

    private ?TestKernel $kernel = null;

    /** @var callable|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        putenv('MESSENGER_CENTRAL_TEST_DSN=in-memory://user:s3cr3t@localhost?token=abc');
        $this->exceptionHandler = self::exceptionHandler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        putenv('MESSENGER_CENTRAL_TEST_DSN');

        // Some FrameworkBundle versions leave Symfony's exception handler registered
        for ($i = 0; $i < 5 && self::exceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private static function exceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    public function testItCatalogsTheTransports(): void
    {
        // Given
        $container = $this->boot(self::MESSENGER)->getContainer()->get('test.service_container');
        static::assertInstanceOf(ContainerInterface::class, $container);

        // When
        $catalog = $container->get(CollectTransportsPass::CATALOG_ID);

        // Then
        static::assertInstanceOf(TransportCatalog::class, $catalog);
        static::assertSame(['async', 'high', 'failed', 'sync'], array_keys($catalog->all()));

        $async = $catalog->get('async');
        static::assertSame('in-memory://', $async->dsn);
        static::assertSame(['serialize' => true], $async->options);
        static::assertSame([RoutedByClass::class], $async->messageClasses);
        static::assertSame('failed', $async->failureTransportName);
        static::assertSame(5, $async->retryStrategy['max_retries'] ?? null);
        static::assertSame(500, $async->retryStrategy['delay'] ?? null);
        static::assertTrue($async->consumable);
        static::assertFalse($async->failureTransport);

        $high = $catalog->get('high');
        static::assertSame(
            'in-memory://user:s3cr3t@localhost?token=abc',
            $high->dsn,
            'Environment variables are resolved at runtime',
        );
        static::assertSame('in-memory://user:****@localhost?token=****', $high->maskedDsn());
        static::assertSame([AttributeMessage::class], $high->messageClasses);

        static::assertTrue($catalog->get('failed')->failureTransport);
        static::assertFalse($catalog->get('sync')->consumable);
    }

    public function testItRegistersTheCommand(): void
    {
        // Given
        $application = new Application($this->boot(self::MESSENGER));

        // When
        $tester = new CommandTester($application->find('messenger:central'));
        $tester->execute([]);

        // Then
        static::assertSame(1, $tester->getStatusCode(), 'The command refuses to run without a terminal');
        static::assertStringContainsString('needs an interactive terminal', $tester->getDisplay());
    }

    public function testItStaysOutOfTheWayWithoutMessenger(): void
    {
        // Given
        $kernel = $this->boot(['enabled' => false]);

        // When
        $container = $kernel->getContainer()->get('test.service_container');

        // Then
        static::assertInstanceOf(ContainerInterface::class, $container);
        static::assertFalse($container->has(CollectTransportsPass::CATALOG_ID));
        static::assertFalse(new Application($kernel)->has('messenger:central'));
    }

    /**
     * @param array<string, mixed> $messenger
     */
    private function boot(array $messenger): TestKernel
    {
        $this->kernel = new TestKernel($messenger);
        $this->kernel->boot();

        return $this->kernel;
    }
}
