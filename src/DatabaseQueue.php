<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\FailedMessage;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\QueueDatabase\Repository\StoredMessage;
use Dirthara\Database\Exception\TransactionException;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;
use Dirthara\QueueDatabase\Repository\QueueMessageRepository;
use Dirthara\QueueDatabase\Repository\FailedMessageRepository;
use Dirthara\Queue\Contract\FailedMessageRepository as FailedMessageRepositoryContract;

use function intdiv;
use function sprintf;

final readonly class DatabaseQueue implements Queue, FailedMessageRepositoryContract
{
    private const string LATEST = '9999-12-31 23:59:59';

    private QueueMessageRepository $messages;

    private FailedMessageRepository $failedMessages;

    public function __construct(
        private ConnectedDatabase $database,
        private ClockInterface $clock,
        private string $queue,
        private string $table,
        private string $failedTable,
        private Duration $reservationTimeout,
    ) {
        $this->messages = new QueueMessageRepository($database, $queue, $table);
        $this->failedMessages = new FailedMessageRepository($database, $queue, $failedTable);
    }

    /**
     * @throws QueueOperationException
     */
    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        $now = $this->clock->now();

        try {
            $this->messages->insert($message, $this->after($now, $delay), $now);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::enqueueFailed($this->queue, $this->table, $this->connection(), $exception);
        }
    }

    /**
     * @throws QueueOperationException
     */
    public function reserve(): ?Delivery
    {
        try {
            $stored = $this->claimNext();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::reserveFailed($this->queue, $this->table, $this->connection(), $exception);
        }

        return $stored === null ? null : $this->delivery($stored);
    }

    /**
     * @return list<FailedMessage>
     *
     * @throws QueueOperationException
     */
    public function failed(): iterable
    {
        try {
            return $this->failedMessages->all();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::readFailedMessagesFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $exception,
            );
        }
    }

    /**
     * @throws QueueOperationException
     */
    public function findFailed(string $id): ?FailedMessage
    {
        try {
            return $this->failedMessages->find($id);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::findFailedMessageFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $id,
                $exception,
            );
        }
    }

    /**
     * @throws FailedMessageNotFoundException
     * @throws QueueOperationException
     */
    public function retry(string $id): void
    {
        try {
            $this->database->transaction(function () use ($id): void {
                $failed = $this->failedMessages->find($id) ?? throw FailedMessageNotFoundException::forId($id);

                if (!$this->failedMessages->delete($id)) {
                    throw FailedMessageNotFoundException::forId($id);
                }

                $now = $this->clock->now();

                $this->messages->insert($failed->message, $now, $now);
            });
        } catch (QueryException|ConnectionException|TransactionException $exception) {
            throw QueueOperationException::retryFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $id,
                $exception,
            );
        }
    }

    /**
     * @throws FailedMessageNotFoundException
     * @throws QueueOperationException
     */
    public function forget(string $id): void
    {
        try {
            $deleted = $this->failedMessages->delete($id);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::forgetFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $id,
                $exception,
            );
        }

        if (!$deleted) {
            throw FailedMessageNotFoundException::forId($id);
        }
    }

    /**
     * @throws QueueOperationException
     */
    public function purgeFailed(DateTimeImmutable $before): int
    {
        try {
            return $this->failedMessages->purge($before);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::purgeFailedMessagesFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $exception,
            );
        }
    }

    /**
     * @throws QueueOperationException
     */
    public function truncateFailed(): int
    {
        try {
            return $this->failedMessages->truncate();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::truncateFailedMessagesFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $exception,
            );
        }
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    private function claimNext(): ?StoredMessage
    {
        do {
            $now = $this->clock->now();
            $stored = $this->messages->nextAvailable($now);
        } while (
            $stored !== null
            && !$this->messages->claim($stored, $now, $this->after($now, $this->reservationTimeout))
        );

        return $stored;
    }

    private function delivery(StoredMessage $stored): DatabaseDelivery
    {
        $id = $stored->id;
        $attempt = $stored->attempts + 1;
        $message = $stored->message;

        return new DatabaseDelivery(
            queuedMessage: $message,
            deliveryAttempt: $attempt,
            acknowledge: fn() => $this->acknowledge($id, $attempt),
            release: fn(?Duration $delay) => $this->release($id, $attempt, $delay),
            fail: fn(?Throwable $throwable) => $this->fail($id, $attempt, $message, $throwable),
        );
    }

    /**
     * @throws QueueOperationException
     */
    private function acknowledge(int $id, int $attempt): void
    {
        try {
            $deleted = $this->messages->delete($id, $attempt);
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::acknowledgeFailed(
                $this->queue,
                $this->table,
                $this->connection(),
                $exception,
            );
        }

        if (!$deleted) {
            throw $this->reservationLost($id, $attempt);
        }
    }

    /**
     * @throws QueueOperationException
     */
    private function release(int $id, int $attempt, ?Duration $delay): void
    {
        try {
            $held = $this->messages->makeAvailable($id, $attempt, $this->after($this->clock->now(), $delay));
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::releaseFailed($this->queue, $this->table, $this->connection(), $exception);
        }

        if (!$held) {
            throw $this->reservationLost($id, $attempt);
        }
    }

    /**
     * @throws QueueOperationException
     */
    private function fail(int $id, int $attempt, QueuedMessage $message, ?Throwable $throwable): void
    {
        $failure = $throwable === null ? null : Failure::fromThrowable($throwable);

        try {
            $this->database->transaction(function () use ($id, $attempt, $message, $failure): void {
                if (!$this->messages->delete($id, $attempt)) {
                    throw $this->reservationLost($id, $attempt);
                }

                $this->failedMessages->insert($message, $attempt, $failure, $this->clock->now());
            });
        } catch (QueryException|ConnectionException|TransactionException $exception) {
            throw QueueOperationException::failFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $exception,
            );
        }
    }

    private function reservationLost(int $id, int $attempt): QueueOperationException
    {
        return QueueOperationException::reservationLost($this->queue, $this->table, $this->connection(), $id, $attempt);
    }

    private function after(DateTimeImmutable $time, ?Duration $delay): DateTimeImmutable
    {
        if ($delay === null || $delay->milliseconds === 0) {
            return $time;
        }

        $latest = new DateTimeImmutable(self::LATEST, new DateTimeZone('UTC'));

        if ($delay->milliseconds >= (($latest->getTimestamp() - $time->getTimestamp()) * 1000)) {
            return $latest;
        }

        $after = $time->modify(sprintf(
            '+%d seconds +%d microseconds',
            intdiv($delay->milliseconds, num2: 1000),
            ($delay->milliseconds % 1000) * 1000,
        ));
        $partial = (int) $after->format('u') % 1000;

        return $partial === 0 ? $after : $after->modify(sprintf('+%d microseconds', 1000 - $partial));
    }

    private function connection(): string
    {
        return $this->database->connection()->name();
    }
}
