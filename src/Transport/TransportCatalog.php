<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use InvalidArgumentException;

use function array_values;
use function sprintf;

/**
 * Every transport declared in the application, in configuration order.
 */
final readonly class TransportCatalog
{
    /** @var array<array-key, TransportDefinition> by name */
    private array $transports;

    /**
     * @param array<array-key, array{
     *     dsn?: string|null,
     *     options?: array<array-key, mixed>,
     *     consumable?: bool,
     *     failure_transport?: bool,
     *     failure_transport_name?: string|null,
     *     retry_strategy?: array<string, int|float>|null,
     *     serializer?: string|null,
     *     signed?: bool,
     *     claim_check?: bool,
     *     priority?: int|null,
     *     message_classes?: list<string>,
     * }> $transports compiled by CollectTransportsPass
     */
    public function __construct(array $transports)
    {
        $definitions = [];
        foreach ($transports as $name => $data) {
            // PHP turns a name like "123" into an integer key
            $definitions[$name] = TransportDefinition::fromArray((string) $name, $data);
        }

        $this->transports = $definitions;
    }

    /**
     * @return list<TransportDefinition>
     */
    public function all(): array
    {
        return array_values($this->transports);
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
