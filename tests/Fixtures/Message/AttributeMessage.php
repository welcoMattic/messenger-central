<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'high')]
final class AttributeMessage {}
