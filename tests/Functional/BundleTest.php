<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Welcomattic\MessengerCentral\DependencyInjection\CollectTransportsPass;
use Welcomattic\MessengerCentral\Tests\Fixtures\App\TestKernel;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\AttributeMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function array_map;
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
            // An integer key, as YAML gives for "123:"
            123 => ['dsn' => 'in-memory://', 'retry_strategy' => ['service' => 'test.retry_strategy']],
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
        static::assertSame(
            ['custom', 'async', 'high', 'failed', 'sync', '123'],
            array_map(static fn(TransportDefinition $transport): string => $transport->name, $catalog->all()),
        );

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

        $numeric = $catalog->get('123');
        static::assertSame('in-memory://', $numeric->dsn);
        static::assertSame('failed', $numeric->failureTransportName);
        static::assertNull($numeric->retryStrategy, 'A retry strategy service');

        $custom = $catalog->get('custom');
        static::assertNull($custom->dsn);
        static::assertTrue($custom->consumable);
    }

    public function testItConfiguresTheWorkers(): void
    {
        // Given
        $kernel = $this->boot(self::MESSENGER, [
            'consume_options' => ['--time-limit=60'],
            'concurrency' => 4,
            'stop_timeout' => 30,
        ]);

        // When
        $container = $kernel->getContainer()->get('test.service_container');

        // Then
        static::assertInstanceOf(ContainerInterface::class, $container);
        static::assertInstanceOf(WorkerSupervisor::class, $container->get('messenger_central.worker_supervisor'));
    }

    public function testItRefusesTheConcurrencyInTheConsumeOptions(): void
    {
        // Then
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set the "concurrency" option instead of "--concurrency".');

        // When
        $this->boot(self::MESSENGER, ['consume_options' => ['--concurrency=4']]);
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
     * @param array<array-key, mixed> $messenger
     * @param array<string, mixed>    $central
     */
    private function boot(array $messenger, array $central = []): TestKernel
    {
        $this->kernel = new TestKernel($messenger, $central);
        $this->kernel->boot();

        return $this->kernel;
    }
}
