<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Welcomattic\MessengerCentral\Failure\FailedMessageException;
use Welcomattic\MessengerCentral\Failure\MessageJson;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\Address;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\Line;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\Parcel;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\PlaceOrder;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\Priority;
use Welcomattic\MessengerCentral\Tests\Failure\Fixtures\Status;

final class MessageJsonTest extends TestCase
{
    private const string JSON = <<<'JSON'
        {
            "billing": null,
            "parent": null,
            "email": "bad@example",
            "amount": 10.0,
            "status": "pending",
            "priority": "Low",
            "placedAt": "2026-10-13T10:42:01.500000+02:00",
            "shipping": {
                "street": "1 rue de la Paix",
                "city": "Paris"
            },
            "lines": [
                {
                    "sku": "A-1",
                    "quantity": 2
                }
            ],
            "origin": "child"
        }
        JSON;

    public function testItExportsThePropertiesOfAMessage(): void
    {
        // When
        $export = MessageJson::export(self::order());

        // Then
        static::assertNotNull($export);
        static::assertSame(self::JSON, $export['json']);
        static::assertSame(['callback'], $export['kept']);
    }

    public function testItGivesTheMessageBackWhenNothingChanged(): void
    {
        // Given
        $order = self::order();

        // When
        $imported = MessageJson::import($order, self::JSON);

        // Then
        static::assertSame($order, $imported);
    }

    public function testItRebuildsTheMessageWithTheEditedProperties(): void
    {
        // Given
        $order = self::order();
        $json = <<<'JSON'
            {
                "billing": {"street": "3 place Garibaldi", "city": "Nice"},
                "email": "good@example.com",
                "amount": 12,
                "status": "paid",
                "priority": "High",
                "shipping": {"street": "2 quai Saint-Antoine", "city": "Lyon"},
                "lines": [
                    {"sku": "A-1", "quantity": 3},
                    {"sku": "B-2", "quantity": 1}
                ]
            }
            JSON;

        // When
        $imported = MessageJson::import($order, $json);

        // Then
        static::assertInstanceOf(PlaceOrder::class, $imported);
        static::assertNotSame($order, $imported);
        static::assertSame('good@example.com', $imported->email());
        static::assertSame(12.0, $imported->amount);
        static::assertSame(Status::Paid, $imported->status);
        static::assertSame(Priority::High, $imported->priority);
        static::assertEquals(new Address('2 quai Saint-Antoine', 'Lyon'), $imported->shipping);
        static::assertEquals(new Address('3 place Garibaldi', 'Nice'), $imported->billing);
        static::assertEquals([new Line('A-1', 3), new Line('B-2', 1)], $imported->lines);

        // Left out of the document, or unchanged
        static::assertSame($order->placedAt, $imported->placedAt);
        static::assertSame($order->callback, $imported->callback);
        static::assertSame('child', $imported->childOrigin());
        static::assertSame('parent', $imported->origin());
        static::assertSame('bad@example', $order->email(), 'The message is not changed');
    }

    public function testItReadsDatesInTheirOriginalClass(): void
    {
        // When
        $imported = MessageJson::import(self::order(), '{"placedAt": "2026-10-14T08:00:00+00:00"}');

        // Then
        static::assertInstanceOf(PlaceOrder::class, $imported);
        static::assertSame('2026-10-14T08:00:00+00:00', $imported->placedAt->format(DATE_ATOM));
    }

    public function testItLeavesCyclesOut(): void
    {
        // Given
        $order = self::order();
        $order->parent = $order;

        // When
        $export = MessageJson::export($order);

        // Then
        static::assertNotNull($export);
        static::assertSame(['parent', 'callback'], $export['kept']);
        static::assertSame($order, MessageJson::import($order, $export['json']));
    }

    public function testItDoesNotEditInstancesOfPhpClasses(): void
    {
        static::assertNull(MessageJson::export(new ArrayObject()));
        static::assertNull(MessageJson::export(
            new MessageDecodingFailedException('Message class "App\Gone" not found.'),
        ));

        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage('This message cannot be edited.');

        MessageJson::import(new ArrayObject(), '{}');
    }

    public function testItRunsTheSetHooksOfTheEditedProperties(): void
    {
        // Given
        $parcel = new Parcel('ups');

        // When
        $imported = MessageJson::import($parcel, '{"carrier": "dhl", "weight": 2}');
        $unchanged = MessageJson::import($parcel, '{"carrier": "UPS", "weight": 3}');

        // Then
        static::assertInstanceOf(Parcel::class, $imported);
        static::assertSame('DHL', $imported->carrier);
        static::assertSame(2, $imported->weight);
        static::assertInstanceOf(Parcel::class, $unchanged);
        static::assertSame('UPS', $unchanged->carrier);
    }

    public function testItBuildsNewObjectsThroughTheirConstructor(): void
    {
        // When
        $imported = MessageJson::import(new Parcel('ups'), '{"next": {"carrier": "fedex"}}');

        // Then: the defaults of the constructor are applied
        static::assertInstanceOf(Parcel::class, $imported);
        static::assertInstanceOf(Parcel::class, $imported->next);
        static::assertSame('FEDEX', $imported->next->carrier);
        static::assertSame(1, $imported->next->weight);
        static::assertNull($imported->next->next);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'invalid JSON' => ['{', 'The message is not valid JSON: Syntax error.'];
        yield 'not an object' => ['[1, 2]', 'The message must be a JSON object.'];
        yield 'unknown property' => [
            '{"mail": "good@example.com"}',
            '"mail" is not a property of ' . PlaceOrder::class . '.',
        ];
        yield 'unknown nested property' => [
            '{"shipping": {"zip": "75002"}}',
            '"shipping.zip" is not a property of ' . Address::class . '.',
        ];
        yield 'wrong type' => [
            '{"amount": "ten"}',
            '"amount": Cannot assign string to property ' . PlaceOrder::class . '::$amount of type float',
        ];
        yield 'wrong type in a list' => [
            '{"lines": [{"sku": "A-1", "quantity": "many"}]}',
            '"lines[0].quantity": Cannot assign string to property ' . Line::class . '::$quantity of type int',
        ];
        yield 'unknown case' => ['{"status": "lost"}', '"status": "lost" is not a case of ' . Status::class . '.'];
        yield 'unknown unit case' => [
            '{"priority": "Urgent"}',
            '"priority": "Urgent" is not a case of ' . Priority::class . '.',
        ];
        yield 'invalid date' => ['{"placedAt": "someday"}', '"placedAt": "someday" is not a date.'];
        yield 'object expected' => ['{"shipping": "Paris"}', '"shipping" must be a JSON object.'];
        yield 'not editable' => ['{"callback": "strlen"}', '"callback" cannot be edited.'];
        yield 'missing in a new object' => [
            '{"billing": {"street": "3 place Garibaldi"}}',
            '"billing.city" is missing to build ' . Address::class . '.',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidParcels(): iterable
    {
        yield 'missing in a new object' => [
            '{"next": {"weight": 2}}',
            '"next.carrier" is missing to build ' . Parcel::class . '.',
        ];
        yield 'refused by the constructor' => [
            '{"next": {"carrier": ""}}',
            '"next": ' . Parcel::class . ' cannot be built: A parcel needs a carrier.',
        ];
    }

    #[DataProvider('invalidParcels')]
    public function testItRefusesANewObjectThatItsConstructorRefuses(string $json, string $error): void
    {
        // Then
        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage($error);

        // When
        MessageJson::import(new Parcel('ups'), $json);
    }

    #[DataProvider('invalidDocuments')]
    public function testItRefusesADocumentThatDoesNotFit(string $json, string $error): void
    {
        // Then
        $this->expectException(FailedMessageException::class);
        $this->expectExceptionMessage($error);

        // When
        MessageJson::import(self::order(), $json);
    }

    private static function order(): PlaceOrder
    {
        return new PlaceOrder(
            'bad@example',
            10.0,
            Status::Pending,
            Priority::Low,
            new DateTimeImmutable('2026-10-13 10:42:01.5', new DateTimeZone('Europe/Paris')),
            new Address('1 rue de la Paix', 'Paris'),
            [new Line('A-1', 2)],
            static fn(): null => null,
        );
    }
}
