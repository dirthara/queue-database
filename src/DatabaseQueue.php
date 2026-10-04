<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\FailedMessage;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\Queue\Contract\FailedMessageRepository;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

final readonly class DatabaseQueue implements Queue, FailedMessageRepository
{
    public function __construct(
        private ConnectedDatabase $database,
        private ClockInterface $clock,
        private string $queue,
        private string $table,
        private string $failedTable,
    ) {}

    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        $now = $this->milliseconds($this->clock->now());

        try {
            $this->database
                ->table($this->table)
                ->insert([
                    'queue' => $this->queue,
                    'type' => $message->type,
                    'payload' => $message->payload,
                    'attempts' => 0,
                    'available_at' => $now + ($delay->milliseconds ?? 0),
                    'created_at' => $now,
                ]);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::enqueueFailed(
                queue: $this->queue,
                table: $this->table,
                connection: $this->database->connection()->name(),
                previous: $exception,
            );
        }
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

    private function milliseconds(DateTimeImmutable $time): int
    {
        return ($time->getTimestamp() * 1000) + (int) $time->format('v');
    }
}
