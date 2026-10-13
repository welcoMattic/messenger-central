<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Failure;

/**
 * A message of a failure transport, ready to be edited.
 */
final readonly class FailedMessage
{
    /**
     * @param string|null  $from transport the message failed on
     * @param string|null  $json the properties of the message, null when it cannot be edited
     * @param list<string> $kept properties left out of the JSON document, which are kept as they are
     */
    public function __construct(
        public string $id,
        public string $class,
        public ?string $from = null,
        public ?float $failedAt = null,
        public ?string $error = null,
        public ?string $json = null,
        public array $kept = [],
    ) {}
}
