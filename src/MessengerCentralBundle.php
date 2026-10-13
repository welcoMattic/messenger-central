<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Welcomattic\MessengerCentral\Command\CentralCommand;
use Welcomattic\MessengerCentral\DependencyInjection\CollectTransportsPass;
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Worker\ConsumeProcessFactory;
use Welcomattic\MessengerCentral\Worker\WorkerReporter;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MessengerCentralBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition
            ->rootNode()
            ->children()
            ->scalarNode('console_path')
            ->info('The console used to start "messenger:consume".')
            ->defaultValue('%kernel.project_dir%/bin/console')
            ->end()
            ->arrayNode('consume_options')
            ->info('Extra options given to "messenger:consume", like "--time-limit=3600".')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->end();
    }

    /**
     * @param array{console_path: string, consume_options: list<string>} $config validated by configure()
     *
     * @mago-expect analysis:incompatible-parameter-type
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(CollectTransportsPass::CATALOG_ID, TransportCatalog::class)->args([[]]);

        $services->set('messenger_central.queue_inspector', QueueInspector::class)->args([service(
            'messenger.receiver_locator',
        )]);

        $services->set('messenger_central.process_factory', ConsumeProcessFactory::class)->args([
            $config['console_path'],
            param('kernel.environment'),
            $config['consume_options'],
        ]);

        $services->set('messenger_central.worker_supervisor', WorkerSupervisor::class)->args([service(
            'messenger_central.process_factory',
        )]);

        $services
            ->set('messenger_central.worker_reporter', WorkerReporter::class)
            ->args(['%env(default::' . WorkerReporter::ENV_VAR . ')%'])
            ->tag('kernel.event_subscriber');

        $services
            ->set('messenger_central.command', CentralCommand::class)
            ->args([
                service(CollectTransportsPass::CATALOG_ID),
                service('messenger_central.worker_supervisor'),
                service('messenger_central.queue_inspector'),
            ])
            ->tag('console.command');
    }

    public function build(ContainerBuilder $container): void
    {
        // After MessengerPass, before decorators are applied
        $container->addCompilerPass(new CollectTransportsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
    }
}
