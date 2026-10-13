<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}
