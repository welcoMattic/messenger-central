<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Failure;

use RuntimeException;

/**
 * A failed message cannot be read, edited or sent back, for a reason that
 * can be shown as is.
 */
final class FailedMessageException extends RuntimeException {}
