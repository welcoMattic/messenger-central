<?php

declare(strict_types=1);

/*
 * Mago extension worker of this project, declared in mago.toml.
 */

use Mago\Sdk\Extension;
use Mago\Sdk\Worker;
use Welcomattic\MessengerCentral\Tools\Mago\ConfigTreeIndentationRule;

require __DIR__ . '/../../vendor/autoload.php';

new Worker(new Extension(
    identifier: 'welcomattic/messenger-central',
    name: 'Messenger Central',
    version: '1.0.0',
    linterRules: [new ConfigTreeIndentationRule()],
))->run();
