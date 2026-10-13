<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

/**
 * The messages waiting in a transport at a given time.
 */
final readonly class QueueSnapshot
{
    /**
     * @param int|null                                        $count    null when the transport cannot count its messages
     * @param list<array{class: string, id: string|null}>|null $messages null when the transport cannot list its messages
     */
    public function __construct(
        public float $at,
        public ?int $count = null,
        public ?array $messages = null,
        public ?string $error = null,
    ) {}
}
