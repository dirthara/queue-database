<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Repository;

use Dirthara\Queue\ValueObject\QueuedMessage;

/**
 * @internal
 */
final readonly class StoredMessage
{
    public function __construct(
        public int $id,
        public int $attempts,
        public QueuedMessage $message,
    ) {}
}
