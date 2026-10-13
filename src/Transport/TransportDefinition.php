<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Transport;

use function explode;
use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;

/**
 * What the container knows about a Messenger transport.
 */
final readonly class TransportDefinition
{
    private const string MASK = '****';
    private const string SENSITIVE_KEY_PATTERN = '/pass|secret|token|auth|access_key|api_key|credential/i';

    /**
     * @param array<array-key, mixed>   $options
     * @param array<string, int|float>|null $retryStrategy
     * @param list<string>              $messageClasses
     */
    public function __construct(
        public string $name,
        public ?string $dsn = null,
        public array $options = [],
        public bool $consumable = true,
        public bool $failureTransport = false,
        public ?string $failureTransportName = null,
        public ?array $retryStrategy = null,
        public ?string $serializer = null,
        public array $messageClasses = [],
    ) {}

    /**
     * @param array{
     *     dsn?: string|null,
     *     options?: array<array-key, mixed>,
     *     consumable?: bool,
     *     failure_transport?: bool,
     *     failure_transport_name?: string|null,
     *     retry_strategy?: array<string, int|float>|null,
     *     serializer?: string|null,
     *     message_classes?: list<string>,
     * } $data
     */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            $name,
            $data['dsn'] ?? null,
            $data['options'] ?? [],
            $data['consumable'] ?? true,
            $data['failure_transport'] ?? false,
            $data['failure_transport_name'] ?? null,
            $data['retry_strategy'] ?? null,
            $data['serializer'] ?? null,
            $data['message_classes'] ?? [],
        );
    }

    /**
     * The DSN with credentials replaced, safe to display.
     */
    public function maskedDsn(): ?string
    {
        if (null === $this->dsn) {
            return null;
        }

        $dsn = (string) preg_replace_callback(
            '#^([a-z][a-z0-9+.-]*://)([^@/?\#]+)@#i',
            static function (array $match): string {
                $userInfo = $match[2];
                $user = str_contains($userInfo, ':') ? explode(':', $userInfo, 2)[0] . ':' : '';

                return $match[1] . $user . self::MASK . '@';
            },
            $this->dsn,
        );

        return (string) preg_replace(
            '/([?&][^=&]*(?:pass|secret|token|auth|access_key|api_key)[^=&]*=)[^&]*/i',
            '$1' . self::MASK,
            $dsn,
        );
    }

    /**
     * The options with secret-looking values replaced, safe to display.
     *
     * @return array<array-key, mixed>
     */
    public function maskedOptions(): array
    {
        return self::maskArray($this->options);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function maskArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (null === $value) {
                continue;
            }

            if (is_string($key) && 1 === preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                // The whole value, even a list like Redis' ['user', 'password']
                $values[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::maskArray($value);
            }
        }

        return $values;
    }
}
