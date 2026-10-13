<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Worker;

enum WorkerStatus: string
{
    case Starting = 'starting';
    case Idle = 'idle';
    case Busy = 'busy';
    case Stopping = 'stopping';
    case Exited = 'exited';

    public function isAlive(): bool
    {
        return self::Exited !== $this;
    }
}
