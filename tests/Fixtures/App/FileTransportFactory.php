<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\App;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * Builds the transports of "test-file://<directory>" DSNs.
 *
 * @implements TransportFactoryInterface<FileTransport>
 */
final class FileTransportFactory implements TransportFactoryInterface
{
    private const string SCHEME = 'test-file://';

    public function createTransport(
        #[\SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        return new FileTransport(substr($dsn, strlen(self::SCHEME)), $serializer);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
