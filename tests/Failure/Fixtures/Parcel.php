<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

use InvalidArgumentException;

use function strtoupper;

/**
 * A message whose constructor checks its values, with a property hook.
 */
final class Parcel
{
    public string $carrier {
        set(string $value) {
            $this->carrier = strtoupper($value);
        }
    }

    public function __construct(
        string $carrier,
        public ?Parcel $next = null,
        public int $weight = 1,
    ) {
        if ('' === $carrier) {
            throw new InvalidArgumentException('A parcel needs a carrier.');
        }

        $this->carrier = $carrier;
    }
}
