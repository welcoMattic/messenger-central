<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Welcomattic\MessengerCentral\Transport\MessageRouter;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Snapshots the Messenger transports, their options and routed messages
 * into the transport catalog.
 *
 * It runs after Messenger's own pass and before the optimization passes,
 * so that transport definitions are still the ones built from the
 * configuration (decorators are not applied yet).
 */
final class CollectTransportsPass implements CompilerPassInterface
{
    public const string CATALOG_ID = 'messenger_central.transport_catalog';

    private const array SERVICE_IDS = [
        self::CATALOG_ID,
        'messenger_central.queue_inspector',
        'messenger_central.queue_probe',
        'messenger_central.probe_command',
        'messenger_central.failed_messages',
        'messenger_central.process_factory',
        'messenger_central.worker_supervisor',
        'messenger_central.worker_reporter',
        'messenger_central.command',
    ];

    private const string TRANSPORT_FACTORY_ID = 'messenger.transport_factory';
    private const string FAILURE_LISTENER_ID = 'messenger.failure.send_failed_message_to_failure_transport_listener';
    private const string RETRY_STRATEGY_PREFIX = 'messenger.retry.multiplier_retry_strategy.';
    private const array RETRY_OPTIONS = ['max_retries', 'delay', 'multiplier', 'max_delay', 'jitter'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CATALOG_ID)) {
            return;
        }

        if (!$container->has('messenger.receiver_locator')) {
            // Messenger is disabled: there is nothing to drive
            foreach (self::SERVICE_IDS as $id) {
                $container->removeDefinition($id);
            }

            return;
        }

        $failureTransportByName = $this->failureTransportByName($container);
        $messagesByTransport = $this->messagesByTransport($container);

        $transports = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            foreach ($tags as $tag) {
                $alias = $tag['alias'] ?? null;
                // A transport named "123" in YAML has an integer name
                $name = is_string($alias) || is_int($alias) ? (string) $alias : $id;
                $priority = $tag['priority'] ?? null;
                $transports[$name] =
                    [
                        'consumable' => false !== ($tag['is_consumable'] ?? true),
                        'failure_transport' => true === ($tag['is_failure_transport'] ?? false),
                        'failure_transport_name' => $failureTransportByName[$name] ?? null,
                        'retry_strategy' => $this->retryStrategy($container, $name),
                        'message_classes' => $messagesByTransport[$name] ?? [],
                        'priority' => is_int($priority) ? $priority : null,
                    ] + $this->factoryArguments($container, $container->getDefinition($id));
            }
        }

        $container->getDefinition(self::CATALOG_ID)->setArgument(0, $transports);
    }

    /**
     * @return array{dsn?: string, options?: array<array-key, mixed>, serializer?: string, signed?: bool, claim_check?: bool}
     */
    private function factoryArguments(ContainerBuilder $container, Definition $definition): array
    {
        $factory = $definition->getFactory();
        if (
            !is_array($factory)
            || !$factory[0] instanceof Reference
            || self::TRANSPORT_FACTORY_ID !== (string) $factory[0]
        ) {
            return [];
        }

        $arguments = $definition->getArguments();
        $result = [];
        if (is_string($arguments[0] ?? null)) {
            $result['dsn'] = $arguments[0];
        }

        if (is_array($arguments[1] ?? null)) {
            $options = $arguments[1];
            unset($options['transport_name']);
            $result['options'] = $options;
        }

        if (($arguments[2] ?? null) instanceof Reference) {
            $result += $this->serializer($container, (string) $arguments[2]);
        }

        return $result;
    }

    /**
     * Symfony 8.2 wraps the serializer of each transport, to sign messages
     * and to claim check large ones: unwraps it to show the configured one.
     *
     * @return array{serializer: string, signed: bool, claim_check: bool}
     */
    private function serializer(ContainerBuilder $container, string $id): array
    {
        $signed = false;
        $claimCheck = false;
        while (str_starts_with($id, '.messenger.transport.') && $container->hasDefinition($id)) {
            // A child definition keeps its replaced arguments as "index_N"
            $arguments = $container->getDefinition($id)->getArguments();

            // Every message, with the "sign" option, and not only the types that require it
            $signedTypes = $arguments['index_2'] ?? [];
            if (
                str_ends_with($id, '.signing_serializer')
                && is_array($signedTypes)
                && in_array('*', $signedTypes, true)
            ) {
                $signed = true;
            }

            if (str_ends_with($id, '.claim_check_serializer')) {
                $claimCheck = true;
            }

            $inner = $arguments['index_0'] ?? $arguments[0] ?? null;
            if (!$inner instanceof Reference) {
                break;
            }

            $id = (string) $inner;
        }

        return ['serializer' => $id, 'signed' => $signed, 'claim_check' => $claimCheck];
    }

    /**
     * @return array<array-key, string>
     */
    private function failureTransportByName(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition(self::FAILURE_LISTENER_ID)) {
            return [];
        }

        $arguments = $container->getDefinition(self::FAILURE_LISTENER_ID)->getArguments();
        $mapping = $arguments[2] ?? $arguments['index_2'] ?? [];

        $result = [];
        foreach (is_array($mapping) ? $mapping : [] as $transport => $failureTransport) {
            if (!is_string($failureTransport) && !is_int($failureTransport)) {
                continue;
            }

            $result[$transport] = (string) $failureTransport;
        }

        return $result;
    }

    /**
     * @return array<string, int|float>|null
     */
    private function retryStrategy(ContainerBuilder $container, string $transport): ?array
    {
        if (!$container->hasDefinition($id = self::RETRY_STRATEGY_PREFIX . $transport)) {
            return null;
        }

        $arguments = $container->getDefinition($id)->getArguments();
        $strategy = [];
        foreach (self::RETRY_OPTIONS as $index => $option) {
            $key = array_key_exists('index_' . $index, $arguments) ? 'index_' . $index : $index;
            $value = $arguments[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                $strategy[$option] = $value;
            }
        }

        return $strategy;
    }

    /**
     * @return array<string, list<string>>
     */
    private function messagesByTransport(ContainerBuilder $container): array
    {
        $routing = [];
        if ($container->hasDefinition('messenger.senders_locator')) {
            $mapping = $container->getDefinition('messenger.senders_locator')->getArgument(0);
            if (is_array($mapping)) {
                $routing = $mapping;
            }
        }

        $candidates = [];
        foreach (array_keys($routing) as $type) {
            $candidates[(string) $type] = true;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_ends_with($id, '.messenger.handlers_locator')) {
                continue;
            }

            $handlers = $definition->getArguments()[0] ?? [];
            if (is_array($handlers)) {
                foreach (array_keys($handlers) as $type) {
                    $candidates[(string) $type] = true;
                }
            }
        }

        $classes = [];
        foreach (array_keys($candidates) as $candidate) {
            if (str_contains($candidate, '*')) {
                continue;
            }

            $reflection = $container->getReflectionClass($candidate, false);
            if (null !== $reflection && !$reflection->isInterface() && !$reflection->isAbstract()) {
                $classes[] = $reflection->getName();
            }
        }

        /** @var array<string, list<string>> $routing */
        return new MessageRouter($routing)->routeAll($classes);
    }
}
