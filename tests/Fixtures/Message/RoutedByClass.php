<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\Message;

final class RoutedByClass
{
    public function __construct(
        public string $payload = '',
    ) {}
}
