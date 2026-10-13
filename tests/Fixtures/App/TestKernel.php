<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Welcomattic\MessengerCentral\MessengerCentralBundle;

use function sys_get_temp_dir;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $messengerConfig
     */
    public function __construct(
        private readonly array $messengerConfig = [],
        string $environment = 'test',
    ) {
        parent::__construct($environment, true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MessengerCentralBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/messenger-central-tests/' . $this->getConfigHash() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/messenger-central-tests/logs';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                // @mago-expect lint:no-literal-password
                'secret' => 'test',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'messenger' => $this->messengerConfig,
            ]);

            $container->register(TestHandler::class)->setAutoconfigured(true)->setPublic(false);
        });
    }

    private function getConfigHash(): string
    {
        return md5(serialize($this->messengerConfig));
    }
}
