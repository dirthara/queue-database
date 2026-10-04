<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase;

use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;

/**
 * @internal
 */
final class DatabaseDelivery implements Delivery
{
    public QueuedMessage $message {
        get => $this->message;
    }

    public int $attempt {
        get => $this->attempt;
    }

    public function acknowledge(): void {}

    public function release(?Duration $duration = null): void {}

    public function fail(?Throwable $throwable = null): void {}
}
