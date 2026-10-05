<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Repository;

use DateTimeImmutable;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

/**
 * @internal
 */
final readonly class QueueMessageRepository
{
    private const int ATTEMPTS_LIMIT = 2_147_483_647;

    private RowFormat $format;

    public function __construct(
        private ConnectedDatabase $database,
        private string $queue,
        private string $table,
    ) {
        $this->format = new RowFormat($database, $queue, $table);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function insert(QueuedMessage $message, DateTimeImmutable $availableAt, DateTimeImmutable $createdAt): void
    {
        $this->database
            ->table($this->table)
            ->insert([
                'queue' => $this->queue,
                'type' => $message->type,
                'payload' => $this->format->encode($message->payload),
                'attempts' => 0,
                'available_at' => $this->format->instant($availableAt),
                'created_at' => $this->format->second($createdAt),
            ]);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    public function nextAvailable(DateTimeImmutable $now): ?StoredMessage
    {
        $row = $this->database
            ->table($this->table)
            ->where('queue', '=', $this->queue)
            ->where('available_at', '<=', $this->format->instant($now))
            ->orderBy('available_at')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new StoredMessage(
            id: $this->format->integer($row, 'id', minimum: 1),
            attempts: $this->format->integer($row, 'attempts', minimum: 0),
            message: new QueuedMessage(type: $this->format->text($row, 'type'), payload: $this->format->payload($row)),
        );
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    public function claim(StoredMessage $message, DateTimeImmutable $now, DateTimeImmutable $reservedUntil): bool
    {
        if ($message->attempts >= self::ATTEMPTS_LIMIT) {
            throw QueueOperationException::attemptsExhausted(
                $this->queue,
                $this->table,
                $this->database->connection()->name(),
                $message->id,
                $message->attempts,
            );
        }

        return $this->database
            ->table($this->table)
            ->where('id', '=', $message->id)
            ->where('attempts', '=', $message->attempts)
            ->where('available_at', '<=', $this->format->instant($now))
            ->update([
                'attempts' => $message->attempts + 1,
                'available_at' => $this->format->instant($reservedUntil),
            ]) > 0;
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function delete(int $id, int $attempt): bool
    {
        return (
            $this->database->table($this->table)->where('id', '=', $id)->where('attempts', '=', $attempt)->delete() > 0
        );
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function makeAvailable(int $id, int $attempt, DateTimeImmutable $availableAt): bool
    {
        $updated = $this->database
            ->table($this->table)
            ->where('id', '=', $id)
            ->where('attempts', '=', $attempt)
            ->update(['available_at' => $this->format->instant($availableAt)]);

        return $updated > 0 || $this->reserved($id, $attempt);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function reserved(int $id, int $attempt): bool
    {
        return $this->database->table($this->table)->where('id', '=', $id)->where('attempts', '=', $attempt)->exists();
    }
}
