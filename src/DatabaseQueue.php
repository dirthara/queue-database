<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase;

use Psr\Clock\ClockInterface;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\FailedMessage;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Contract\FailedMessageRepository;

final readonly class DatabaseQueue implements Queue, FailedMessageRepository
{
    public function __construct(
        public ConnectedDatabase $database,
        public ClockInterface $clock,
    ) {}

    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        // TODO: Implement enqueue() method.
    }

    public function reserve(): ?Delivery
    {
        // TODO: Implement reserve() method.
    }

    public function failed(): iterable
    {
        // TODO: Implement failed() method.
    }

    public function findFailed(string $id): ?FailedMessage
    {
        // TODO: Implement findFailed() method.
    }

    public function retry(string $id): void
    {
        // TODO: Implement retry() method.
    }

    public function forget(string $id): void
    {
        // TODO: Implement forget() method.
    }
}
