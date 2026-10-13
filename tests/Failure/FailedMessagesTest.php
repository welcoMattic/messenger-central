<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Welcomattic\MessengerCentral\Failure\FailedMessageException;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;

final class FailedMessagesTest extends TestCase
{
    private ListableTransport $async;
    private ListableTransport $failed;
    private FailedMessages $failedMessages;

    protected function setUp(): void
    {
        $this->async = new ListableTransport();
        $this->failed = new ListableTransport();
        $this->failedMessages = new FailedMessages(new ServiceLocator([
            'async' => fn(): ListableTransport => $this->async,
            'failed' => fn(): ListableTransport => $this->failed,
        ]));
    }

    public function testItDescribesAFailedMessage(): void
    {
        // Given
        $id = $this->addFailed(new RoutedByClass('hello'));

        // When
        $message = $this->failedMessages->get('failed', $id);

        // Then
        static::assertNotNull($message);
        static::assertSame($id, $message->id);
        static::assertSame(RoutedByClass::class, $message->class);
        static::assertSame('async', $message->from);
        static::assertSame(1_791_888_121.0, $message->failedAt);
        static::assertSame('RuntimeException: SMTP down', $message->error);
        static::assertSame("{\n    \"payload\": \"hello\"\n}", $message->json);
        static::assertNull($this->failedMessages->get('failed', '404'));
    }

    public function testItTellsEveryErrorOfAFailedMessage(): void
    {
        // Given
        $envelope = $this->failed->send(new Envelope(new RoutedByClass('hello'), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(1),
            new RedeliveryStamp(2),
            new ErrorDetailsStamp(RuntimeException::class, 0, 'SMTP down'),
            ErrorDetailsStamp::create(new RuntimeException("SMTP still down,\nafter a retry")),
            new RedeliveryStamp(0),
        ]));

        // When
        $message = $this->failedMessages->get(
            'failed',
            (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
        );

        // Then
        static::assertNotNull($message);
        static::assertSame(
            ['RuntimeException: SMTP down', "RuntimeException: SMTP still down,\nafter a retry"],
            $message->errors,
        );
        static::assertSame(2, $message->retries);
        static::assertStringContainsString(__FUNCTION__, (string) $message->trace);
    }

    public function testItRemovesFailedMessages(): void
    {
        // Given
        $first = $this->addFailed(new RoutedByClass('first'));
        $this->addFailed(new RoutedByClass('second'));

        // When
        $class = $this->failedMessages->remove('failed', $first);

        // Then
        static::assertSame(RoutedByClass::class, $class);
        static::assertCount(1, $this->failed->envelopes);
        static::assertSame([], $this->async->envelopes);

        // When
        $removed = $this->failedMessages->removeAll('failed');

        // Then
        static::assertSame(1, $removed);
        static::assertSame([], $this->failed->envelopes);
    }

    public function testItSendsEveryFailedMessageBack(): void
    {
        // Given
        $this->addFailed(new RoutedByClass('first'));
        $this->addFailed(new RoutedByClass('second'));
        $this->failed->send(new Envelope(new RoutedByClass('nowhere')));

        // When
        $result = $this->failedMessages->resendAll('failed');

        // Then
        static::assertSame(['sent' => 2, 'kept' => 1], $result);
        static::assertCount(2, $this->async->envelopes);
        static::assertCount(1, $this->failed->envelopes, 'The message that does not tell its transport stays');
    }

    public function testItSendsAMessageBackToTheTransportItFailedOn(): void
    {
        // Given
        $id = $this->addFailed(new RoutedByClass('hello'));

        // When
        $transport = $this->failedMessages->resend('failed', $id, '{"payload": "hello again"}');

        // Then
        static::assertSame('async', $transport);
        static::assertSame([], $this->failed->envelopes);
        static::assertCount(1, $this->async->envelopes);

        $envelope = $this->async->envelopes['1'];
        static::assertEquals(new RoutedByClass('hello again'), $envelope->getMessage());
        static::assertNull(
            $envelope->last(SentToFailureTransportStamp::class),
            'Messenger would not send it to the failure transport again',
        );
        static::assertNull($envelope->last(DelayStamp::class));
        static::assertNull($envelope->last(ReceivedStamp::class));
        static::assertSame('1', $envelope->last(TransportMessageIdStamp::class)?->getId());
        static::assertSame('messenger.bus.default', $envelope->last(BusNameStamp::class)?->getBusName());
        static::assertSame('SMTP down', $envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage());
        static::assertSame(0, $envelope->last(RedeliveryStamp::class)?->getRetryCount());
    }

    public function testItSendsAMessageBackAsIs(): void
    {
        // Given
        $message = new RoutedByClass('hello');
        $id = $this->addFailed($message);

        // When
        $transport = $this->failedMessages->resend('failed', $id);

        // Then
        static::assertSame('async', $transport);
        static::assertSame([], $this->failed->envelopes);
        static::assertSame($message, $this->async->envelopes['1']->getMessage());
        static::assertNull($this->async->envelopes['1']->last(SentToFailureTransportStamp::class));
    }

    public function testItSendsBackAMessageThatCannotBeEdited(): void
    {
        // Given
        $id = $this->addFailed(new MessageDecodingFailedException('Message class "App\\Gone" not found.'));
        $message = $this->failedMessages->get('failed', $id);
        static::assertNotNull($message);
        static::assertNull($message->json);

        // When
        $this->failedMessages->resend('failed', $id);

        // Then
        static::assertSame([], $this->failed->envelopes);
        static::assertInstanceOf(MessageDecodingFailedException::class, $this->async->envelopes['1']->getMessage());
    }

    public function testItKeepsTheMessageWhenItCannotBeSentBack(): void
    {
        // Given
        $id = $this->addFailed(new RoutedByClass('hello'));
        $this->async->failOnSend = true;

        // When
        try {
            $this->failedMessages->resend('failed', $id, '{"payload": "hello again"}');
            static::fail('The message should not be sent');
        } catch (RuntimeException $error) {
            static::assertSame('The broker went away', $error->getMessage());
        }

        // Then
        static::assertCount(1, $this->failed->envelopes);
    }

    public function testItKeepsTheMessageWhenTheDocumentDoesNotFit(): void
    {
        // Given
        $id = $this->addFailed(new RoutedByClass('hello'));

        // When
        try {
            $this->failedMessages->resend('failed', $id, '{"payload": ["hello"]}');
            static::fail('The message should not be sent');
        } catch (FailedMessageException $error) {
            static::assertStringStartsWith('"payload": Cannot assign array', $error->getMessage());
        }

        // Then
        static::assertCount(1, $this->failed->envelopes);
        static::assertSame([], $this->async->envelopes);
    }

    public function testItNeedsToKnowWhereTheMessageFailed(): void
    {
        // Given
        $id = (string) $this->failed
            ->send(new Envelope(new RoutedByClass()))
            ->last(TransportMessageIdStamp::class)
            ?->getId();

        // Then
        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage('The message #1 does not tell which transport it failed on.');

        // When
        $this->failedMessages->resend('failed', $id, '{}');
    }

    public function testItTellsWhenTheMessageIsGone(): void
    {
        // Then
        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage('The message #404 is not in "failed" anymore.');

        // When
        $this->failedMessages->resend('failed', '404', '{}');
    }

    public function testItNeedsATransportThatListsItsMessages(): void
    {
        // Given
        $failedMessages = new FailedMessages(new ServiceLocator([
            'sync' => static fn(): SyncTransport => new SyncTransport(new MessageBus()),
        ]));

        // Then
        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage('The "sync" transport cannot list its messages.');

        // When
        $failedMessages->get('sync', '1');
    }

    private function addFailed(object $message): string
    {
        $envelope = $this->failed->send(new Envelope($message, [
            new BusNameStamp('messenger.bus.default'),
            new ErrorDetailsStamp(RuntimeException::class, 0, 'SMTP down'),
            new SentToFailureTransportStamp('async'),
            new DelayStamp(0),
            new RedeliveryStamp(0, new DateTimeImmutable('@1791888121')),
            new ReceivedStamp('async'),
        ]));

        return (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
    }
}
