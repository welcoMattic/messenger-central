<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use InvalidArgumentException;

use function sprintf;

/**
 * Every transport declared in the application, in configuration order.
 */
final readonly class TransportCatalog
{
    /** @var array<string, TransportDefinition> */
    private array $transports;

    /**
     * @param array<string, array{
     *     dsn?: string|null,
     *     options?: array<array-key, mixed>,
     *     consumable?: bool,
     *     failure_transport?: bool,
     *     failure_transport_name?: string|null,
     *     retry_strategy?: array<string, int|float>|null,
     *     serializer?: string|null,
     *     message_classes?: list<string>,
     * }> $transports compiled by CollectTransportsPass
     */
    public function __construct(array $transports)
    {
        $definitions = [];
        foreach ($transports as $name => $data) {
            $definitions[$name] = TransportDefinition::fromArray($name, $data);
        }

        $this->transports = $definitions;
    }

    /**
     * @return array<string, TransportDefinition>
     */
    public function all(): array
    {
        return $this->transports;
    }

    public function has(string $name): bool
    {
        return isset($this->transports[$name]);
    }

    public function get(string $name): TransportDefinition
    {
        return (
            $this->transports[$name] ?? throw new InvalidArgumentException(sprintf('Unknown transport "%s".', $name))
        );
    }
}
