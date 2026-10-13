<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Failure\Fixtures;

use Closure;
use DateTimeImmutable;

final class PlaceOrder extends Message
{
    public ?Address $billing = null;

    public ?self $parent = null;

    /**
     * @param list<Line> $lines
     */
    public function __construct(
        private readonly string $email,
        public readonly float $amount,
        public readonly Status $status,
        public readonly Priority $priority,
        public readonly DateTimeImmutable $placedAt,
        public readonly Address $shipping,
        public readonly array $lines,
        public readonly ?Closure $callback = null,
        private string $origin = 'child',
    ) {}

    public function email(): string
    {
        return $this->email;
    }

    public function childOrigin(): string
    {
        return $this->origin;
    }
}
