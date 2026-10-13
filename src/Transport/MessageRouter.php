<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function str_starts_with;
use function strlen;
use function strval;
use function substr;

/**
 * Tells which transports a message class is sent to.
 *
 * It delegates to Messenger's own SendersLocator so that class, parent,
 * interface, namespace wildcard, "*" fallback and #[AsMessage] rules stay
 * exactly the ones applied at runtime.
 */
final readonly class MessageRouter
{
    private const string TRANSPORT_SERVICE_PREFIX = 'messenger.transport.';

    private SendersLocator $sendersLocator;

    /**
     * @param array<string, list<string>> $routing message type (class, interface or wildcard) => sender aliases
     */
    public function __construct(array $routing)
    {
        $this->sendersLocator = new SendersLocator($routing, new class implements ContainerInterface {
            public function get(string $id): SenderInterface
            {
                return new class implements SenderInterface {
                    public function send(Envelope $envelope): Envelope
                    {
                        return $envelope;
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        });
    }

    /**
     * @return list<string> transport names, empty when the message is handled synchronously
     */
    public function route(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isEnum()) {
            return [];
        }

        try {
            $message = $reflection->newInstanceWithoutConstructor();
        } catch (ReflectionException) {
            return [];
        }

        $senders = [];
        foreach ($this->sendersLocator->getSenders(new Envelope($message)) as $alias => $_) {
            // A transport named "123" in YAML has an integer alias, whatever the declared type
            // @mago-expect analysis:redundant-cast
            $senders[self::toTransportName((string) $alias)] = true;
        }

        return array_map(strval(...), array_keys($senders));
    }

    /**
     * @param iterable<string> $classes
     *
     * @return array<string, list<string>> transport name => message classes
     */
    public function routeAll(iterable $classes): array
    {
        $byTransport = [];
        foreach ($classes as $class) {
            foreach ($this->route($class) as $transport) {
                $byTransport[$transport][$class] = $class;
            }
        }

        $result = [];
        foreach ($byTransport as $transport => $transportClasses) {
            $result[$transport] = array_values($transportClasses);
        }

        return $result;
    }

    private static function toTransportName(string $alias): string
    {
        if (str_starts_with($alias, self::TRANSPORT_SERVICE_PREFIX)) {
            return substr($alias, strlen(self::TRANSPORT_SERVICE_PREFIX));
        }

        return $alias;
    }
}
