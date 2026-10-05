<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Database\Exception\DatabaseException;

use function sprintf;

final class QueueOperationException extends RuntimeException implements QueueDatabaseException
{
    use HasExceptionContext;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    public static function enqueueFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('enqueue a message onto', $queue, $table, $connection, $previous);
    }

    public static function reserveFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('reserve a message from', $queue, $table, $connection, $previous);
    }

    public static function acknowledgeFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('acknowledge a message on', $queue, $table, $connection, $previous);
    }

    public static function releaseFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('release a message back onto', $queue, $table, $connection, $previous);
    }

    public static function failFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('move a failed message off', $queue, $table, $connection, $previous);
    }

    public static function readFailedMessagesFailed(
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return self::failed('read the failed messages of', $queue, $table, $connection, $previous);
    }

    public static function findFailedMessageFailed(
        string $queue,
        string $table,
        string $connection,
        string $id,
        DatabaseException $previous,
    ): self {
        return self::failed('find a failed message of', $queue, $table, $connection, $previous)
            ->addContext([
                'id' => self::printable($id),
            ]);
    }

    public static function retryFailed(
        string $queue,
        string $table,
        string $connection,
        string $id,
        DatabaseException $previous,
    ): self {
        return self::failed('retry a failed message of', $queue, $table, $connection, $previous)
            ->addContext([
                'id' => self::printable($id),
            ]);
    }

    public static function forgetFailed(
        string $queue,
        string $table,
        string $connection,
        string $id,
        DatabaseException $previous,
    ): self {
        return self::failed('forget a failed message of', $queue, $table, $connection, $previous)
            ->addContext([
                'id' => self::printable($id),
            ]);
    }

    public static function reservationLost(
        string $queue,
        string $table,
        string $connection,
        int $id,
        int $attempt,
    ): self {
        return new self(
            message: sprintf(
                'Unable to settle attempt %d of message %d on queue "%s": its reservation expired and the message was '
                . 'reserved again or settled elsewhere.',
                $attempt,
                $id,
                self::printable($queue),
            ),
            context: [
                'queue' => $queue,
                'table' => $table,
                'connection' => $connection,
                'id' => $id,
                'attempt' => $attempt,
            ],
        );
    }

    public static function attemptsExhausted(
        string $queue,
        string $table,
        string $connection,
        int $id,
        int $attempts,
    ): self {
        return new self(
            message: sprintf(
                'Unable to reserve message %d on queue "%s": its attempt counter of %d cannot be incremented any '
                . 'further.',
                $id,
                self::printable($queue),
                $attempts,
            ),
            context: [
                'queue' => $queue,
                'table' => $table,
                'connection' => $connection,
                'id' => $id,
                'attempts' => $attempts,
            ],
        );
    }

    public static function malformedRow(string $queue, string $table, string $connection, string $column): self
    {
        return new self(
            message: sprintf(
                'Unable to read a message of queue "%s" from table "%s": column "%s" holds a malformed value.',
                self::printable($queue),
                self::printable($table),
                self::printable($column),
            ),
            context: [
                'queue' => $queue,
                'table' => $table,
                'connection' => $connection,
                'column' => $column,
            ],
        );
    }

    private static function failed(
        string $operation,
        string $queue,
        string $table,
        string $connection,
        DatabaseException $previous,
    ): self {
        return new self(
            message: sprintf(
                'Unable to %s queue "%s" in table "%s" on connection "%s".',
                $operation,
                self::printable($queue),
                self::printable($table),
                self::printable($connection),
            ),
            previous: $previous,
            context: [
                'queue' => $queue,
                'table' => $table,
                'connection' => $connection,
            ],
        );
    }
}
