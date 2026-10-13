<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * The messages waiting in a transport at a given time.
 */
final readonly class QueueSnapshot
{
    /**
     * @param int|null $count null when the transport cannot count its messages
     * @param list<array{class: string, id: string|null, from: string|null, failed_at: float|null, error: string|null}>|null $messages
     *        null when the transport cannot list its messages; "from" is set for the messages of a failure
     *        transport, "failed_at" and "error" for the messages that failed before
     */
    public function __construct(
        public float $at,
        public ?int $count = null,
        public ?array $messages = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array{at: float, count: int|null, messages: list<array{class: string, id: string|null, from: string|null, failed_at: float|null, error: string|null}>|null, error: string|null}
     */
    public function toArray(): array
    {
        return ['at' => $this->at, 'count' => $this->count, 'messages' => $this->messages, 'error' => $this->error];
    }

    /**
     * @param array<array-key, mixed> $data what toArray() gave, through JSON
     */
    public static function fromArray(array $data): self
    {
        $at = $data['at'] ?? null;
        $count = $data['count'] ?? null;
        $error = $data['error'] ?? null;
        $messages = null;
        if (is_array($data['messages'] ?? null)) {
            $messages = [];
            foreach ($data['messages'] as $message) {
                if (!is_array($message) || !is_string($message['class'] ?? null)) {
                    continue;
                }

                $failedAt = $message['failed_at'] ?? null;
                $messages[] = [
                    'class' => $message['class'],
                    'id' => self::string($message['id'] ?? null),
                    'from' => self::string($message['from'] ?? null),
                    'failed_at' => is_int($failedAt) || is_float($failedAt) ? (float) $failedAt : null,
                    'error' => self::string($message['error'] ?? null),
                ];
            }
        }

        return new self(
            is_int($at) || is_float($at) ? (float) $at : 0.0,
            is_int($count) ? $count : null,
            $messages,
            is_string($error) ? $error : null,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
