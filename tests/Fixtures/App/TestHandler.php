<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Fixtures\App;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\AttributeMessage;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\RoutedByClass;
use Welcomattic\MessengerCentral\Tests\Fixtures\Message\SyncMessage;

final class TestHandler
{
    #[AsMessageHandler]
    public function onRoutedByClass(RoutedByClass $message): void {}

    #[AsMessageHandler]
    public function onAttribute(AttributeMessage $message): void {}

    #[AsMessageHandler]
    public function onSync(SyncMessage $message): void {}
}
