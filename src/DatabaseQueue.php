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
use Dirthara\Queue\Contract\FailedMessageRepository;
use Dirthara\Database\Exception\TransactionException;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

use function intdiv;
use function is_int;
use function sprintf;
use function array_map;
use function is_string;
use function filter_var;
use function preg_match;
use function base64_decode;
use function base64_encode;

use const PHP_INT_MIN;
use const FILTER_VALIDATE_INT;

final readonly class DatabaseQueue implements Queue, FailedMessageRepository
{
    private const string LATEST = '9999-12-31 23:59:59';

    private const int ATTEMPTS_LIMIT = 2_147_483_647;

    private const string INTEGER_PATTERN = '/^-?(?:0|[1-9][0-9]*)$/';

    public function __construct(
        private ConnectedDatabase $database,
        private ClockInterface $clock,
        private string $queue,
        private string $table,
        private string $failedTable,
        private Duration $reservationTimeout,
    ) {}

    /**
     * @throws QueueOperationException
     */
    public function enqueue(QueuedMessage $message, ?Duration $delay = null): void
    {
        $now = $this->clock->now();

        try {
            $this->database
                ->table($this->table)
                ->insert([
                    'queue' => $this->queue,
                    'type' => $message->type,
                    'payload' => base64_encode($message->payload),
                    'attempts' => 0,
                    'available_at' => $this->availableAt($this->after($now, $delay)),
                    'created_at' => $this->dateTime($now),
                ]);
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
            do {
                $now = $this->clock->now();
                $row = $this->nextAvailable($now);

                if ($row === null) {
                    return null;
                }

                $delivery = $this->claim($row, $now);
            } while ($delivery === null);

            return $delivery;
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::reserveFailed($this->queue, $this->table, $this->connection(), $exception);
        }
    }

    /**
     * @return list<FailedMessage>
     *
     * @throws QueueOperationException
     */
    public function failed(): iterable
    {
        try {
            $rows = $this->database->table($this->failedTable)->where('queue', '=', $this->queue)->orderBy('id')->get();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::readFailedMessagesFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $exception,
            );
        }

        return array_map($this->hydrateFailed(...), $rows);
    }

    /**
     * @throws QueueOperationException
     */
    public function findFailed(string $id): ?FailedMessage
    {
        $key = $this->failedKey($id);

        if ($key === null) {
            return null;
        }

        try {
            $row = $this->database
                ->table($this->failedTable)
                ->where('id', '=', $key)
                ->where('queue', '=', $this->queue)
                ->first();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::findFailedMessageFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $id,
                $exception,
            );
        }

        return $row === null ? null : $this->hydrateFailed($row);
    }

    /**
     * @throws FailedMessageNotFoundException
     * @throws QueueOperationException
     */
    public function retry(string $id): void
    {
        $key = $this->failedKey($id) ?? throw FailedMessageNotFoundException::forId($id);

        try {
            $this->database->transaction(function (ConnectedDatabase $database) use ($id, $key): void {
                $row = $database
                    ->table($this->failedTable)
                    ->where('id', '=', $key)
                    ->where('queue', '=', $this->queue)
                    ->first();

                if ($row === null) {
                    throw FailedMessageNotFoundException::forId($id);
                }

                $deleted = $database
                    ->table($this->failedTable)
                    ->where('id', '=', $key)
                    ->where('queue', '=', $this->queue)
                    ->delete();

                if ($deleted === 0) {
                    throw FailedMessageNotFoundException::forId($id);
                }

                $now = $this->clock->now();

                $database
                    ->table($this->table)
                    ->insert([
                        'queue' => $this->queue,
                        'type' => $this->text($row, 'type', $this->failedTable),
                        'payload' => base64_encode($this->payload($row, $this->failedTable)),
                        'attempts' => 0,
                        'available_at' => $this->availableAt($now),
                        'created_at' => $this->dateTime($now),
                    ]);
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
        $key = $this->failedKey($id) ?? throw FailedMessageNotFoundException::forId($id);

        try {
            $deleted = $this->database
                ->table($this->failedTable)
                ->where('id', '=', $key)
                ->where('queue', '=', $this->queue)
                ->delete();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::forgetFailed(
                $this->queue,
                $this->failedTable,
                $this->connection(),
                $id,
                $exception,
            );
        }

        if ($deleted === 0) {
            throw FailedMessageNotFoundException::forId($id);
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws QueryException
     * @throws ConnectionException
     */
    private function nextAvailable(DateTimeImmutable $now): ?array
    {
        return $this->database
            ->table($this->table)
            ->where('queue', '=', $this->queue)
            ->where('available_at', '<=', $this->availableAt($now))
            ->orderBy('available_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    private function claim(array $row, DateTimeImmutable $now): ?DatabaseDelivery
    {
        $id = $this->integer($row, 'id', $this->table, minimum: 1);
        $attempts = $this->integer($row, 'attempts', $this->table, minimum: 0);

        if ($attempts >= self::ATTEMPTS_LIMIT) {
            throw QueueOperationException::attemptsExhausted(
                $this->queue,
                $this->table,
                $this->connection(),
                $id,
                $attempts,
            );
        }

        $attempt = $attempts + 1;

        $message = new QueuedMessage(
            type: $this->text($row, 'type', $this->table),
            payload: $this->payload($row, $this->table),
        );

        $updated = $this->database
            ->table($this->table)
            ->where('id', '=', $id)
            ->where('attempts', '=', $attempts)
            ->where('available_at', '<=', $this->availableAt($now))
            ->update([
                'attempts' => $attempt,
                'available_at' => $this->availableAt($this->after($now, $this->reservationTimeout)),
            ]);

        if ($updated === 0) {
            return null;
        }

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
            $deleted = $this->database
                ->table($this->table)
                ->where('id', '=', $id)
                ->where('attempts', '=', $attempt)
                ->delete();
        } catch (QueryException|ConnectionException $exception) {
            throw QueueOperationException::acknowledgeFailed(
                $this->queue,
                $this->table,
                $this->connection(),
                $exception,
            );
        }

        if ($deleted === 0) {
            throw $this->reservationLost($id, $attempt);
        }
    }

    /**
     * @throws QueueOperationException
     */
    private function release(int $id, int $attempt, ?Duration $delay): void
    {
        try {
            $updated = $this->database
                ->table($this->table)
                ->where('id', '=', $id)
                ->where('attempts', '=', $attempt)
                ->update([
                    'available_at' => $this->availableAt($this->after($this->clock->now(), $delay)),
                ]);

            $held = $updated > 0 || $this->reservationExists($id, $attempt);
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
        $code = $failure?->code;

        try {
            $this->database->transaction(function (ConnectedDatabase $database) use (
                $id,
                $attempt,
                $message,
                $failure,
                $code,
            ): void {
                $deleted = $database
                    ->table($this->table)
                    ->where('id', '=', $id)
                    ->where('attempts', '=', $attempt)
                    ->delete();

                if ($deleted === 0) {
                    throw $this->reservationLost($id, $attempt);
                }

                $database
                    ->table($this->failedTable)
                    ->insert([
                        'queue' => $this->queue,
                        'type' => $message->type,
                        'payload' => base64_encode($message->payload),
                        'failed_attempt' => $attempt,
                        'failure_type' => $failure?->type,
                        'failure_message' => $failure?->message,
                        'failure_code_integer' => is_int($code) ? $code : null,
                        'failure_code_string' => is_string($code) ? $code : null,
                        'failed_at' => $this->dateTime($this->clock->now()),
                    ]);
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

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function reservationExists(int $id, int $attempt): bool
    {
        return $this->database->table($this->table)->where('id', '=', $id)->where('attempts', '=', $attempt)->exists();
    }

    private function reservationLost(int $id, int $attempt): QueueOperationException
    {
        return QueueOperationException::reservationLost($this->queue, $this->table, $this->connection(), $id, $attempt);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function hydrateFailed(array $row): FailedMessage
    {
        return new FailedMessage(
            id: (string) $this->integer($row, 'id', $this->failedTable, minimum: 1),
            message: new QueuedMessage(
                type: $this->text($row, 'type', $this->failedTable),
                payload: $this->payload($row, $this->failedTable),
            ),
            attempt: $this->integer($row, 'failed_attempt', $this->failedTable, minimum: 1),
            failure: ($row['failure_type'] ?? null) === null
                ? null
                : new Failure(
                    type: $this->text($row, 'failure_type', $this->failedTable),
                    message: $this->text($row, 'failure_message', $this->failedTable),
                    code: $this->failureCode($row),
                ),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function failureCode(array $row): int|string
    {
        return match (true) {
            ($row['failure_code_string'] ?? null) === null => $this->integer(
                $row,
                'failure_code_integer',
                $this->failedTable,
                minimum: PHP_INT_MIN,
            ),
            ($row['failure_code_integer'] ?? null) === null => $this->text(
                $row,
                'failure_code_string',
                $this->failedTable,
            ),
            default => throw $this->malformedRow($this->failedTable, 'failure_code_string'),
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function payload(array $row, string $table): string
    {
        $encoded = $this->text($row, 'payload', $table);
        $payload = base64_decode($encoded, strict: true);

        return $payload !== false && base64_encode($payload) === $encoded
            ? $payload
            : throw $this->malformedRow($table, 'payload');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function integer(array $row, string $column, string $table, int $minimum): int
    {
        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;

        $integer = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match(self::INTEGER_PATTERN, $value) === 1 && (string) (int) $value === $value
                => (int) $value,
            default => null,
        };

        return $integer !== null && $integer >= $minimum ? $integer : throw $this->malformedRow($table, $column);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function text(array $row, string $column, string $table): string
    {
        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : throw $this->malformedRow($table, $column);
    }

    private function malformedRow(string $table, string $column): QueueOperationException
    {
        return QueueOperationException::malformedRow($this->queue, $table, $this->connection(), $column);
    }

    private function failedKey(string $id): ?int
    {
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($key) && (string) $key === $id ? $key : null;
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

    private function availableAt(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function dateTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function connection(): string
    {
        return $this->database->connection()->name();
    }
}
