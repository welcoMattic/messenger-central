<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\App;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function bin2hex;
use function count;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hrtime;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function sprintf;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * A transport whose messages are files, shared by the processes of the tests.
 *
 * A message is taken by renaming its file, which only one process can do.
 */
final readonly class FileTransport implements TransportInterface, MessageCountAwareInterface
{
    public function __construct(
        private string $directory,
        private SerializerInterface $serializer,
    ) {}

    public function send(Envelope $envelope): Envelope
    {
        $name = sprintf('%d-%s.msg', hrtime(true), bin2hex(random_bytes(4)));
        file_put_contents(
            $this->directory . '/' . $name,
            json_encode($this->serializer->encode($envelope), JSON_THROW_ON_ERROR),
        );

        return $envelope->with(new TransportMessageIdStamp($name));
    }

    public function get(): iterable
    {
        $files = $this->files();
        foreach ($files as $file) {
            $taken = $file . '.taken';
            if (!self::take($file, $taken)) {
                continue;
            }

            $encoded = json_decode((string) file_get_contents($taken), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($encoded)) {
                throw new RuntimeException(sprintf('"%s" is not a message.', $taken));
            }

            /** @var array{body: string, headers?: array<string, string>} $encoded */
            return [$this->serializer->decode($encoded)->with(new TransportMessageIdStamp($taken))];
        }

        return [];
    }

    public function ack(Envelope $envelope): void
    {
        $this->forget($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->forget($envelope);
    }

    public function getMessageCount(): int
    {
        return count($this->files());
    }

    /**
     * @return list<string> the messages waiting, oldest first
     */
    private function files(): array
    {
        $files = glob($this->directory . '/*.msg');
        $files = false === $files ? [] : $files;
        sort($files);

        return $files;
    }

    private function forget(Envelope $envelope): void
    {
        $file = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Renames the file of a message, which fails when another worker took it.
     */
    private static function take(string $file, string $taken): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return rename($file, $taken);
        } finally {
            restore_error_handler();
        }
    }
}
