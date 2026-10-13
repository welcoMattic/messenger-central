<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

enum Status: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
