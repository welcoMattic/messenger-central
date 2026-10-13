<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

/**
 * A message with a private property of the same name as one of its child.
 */
abstract class Message
{
    private string $origin = 'parent';

    public function origin(): string
    {
        return $this->origin;
    }
}
