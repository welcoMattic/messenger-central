<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

final readonly class Line
{
    public function __construct(
        public string $sku,
        public int $quantity,
    ) {}
}
