<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Repository;

use DateTimeImmutable;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\FailedMessage;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

use function is_int;
use function array_map;
use function is_string;
use function array_find;
use function filter_var;

use const PHP_INT_MIN;
use const FILTER_VALIDATE_INT;

/**
 * @internal
 */
final readonly class FailedMessageRepository
{
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
    public function insert(QueuedMessage $message, int $attempt, ?Failure $failure, DateTimeImmutable $failedAt): void
    {
        $code = $failure?->code;

        $this->database
            ->table($this->table)
            ->insert([
                'queue' => $this->queue,
                'type' => $message->type,
                'payload' => $this->format->encode($message->payload),
                'failed_attempt' => $attempt,
                'failure_type' => $failure?->type,
                'failure_message' => $failure?->message,
                'failure_code_integer' => is_int($code) ? $code : null,
                'failure_code_string' => is_string($code) ? $code : null,
                'failed_at' => $this->format->second($failedAt),
            ]);
    }

    /**
     * @return list<FailedMessage>
     *
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    public function all(): array
    {
        $rows = $this->database->table($this->table)->where('queue', '=', $this->queue)->orderBy('id')->get();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws QueueOperationException
     */
    public function find(string $id): ?FailedMessage
    {
        $key = $this->key($id);

        if ($key === null) {
            return null;
        }

        $row = $this->database->table($this->table)->where('id', '=', $key)->where('queue', '=', $this->queue)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function delete(string $id): bool
    {
        $key = $this->key($id);

        if ($key === null) {
            return false;
        }

        return (
            $this->database->table($this->table)->where('id', '=', $key)->where('queue', '=', $this->queue)->delete()
            > 0
        );
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function purge(DateTimeImmutable $before): int
    {
        return $this->database
            ->table($this->table)
            ->where('queue', '=', $this->queue)
            ->where('failed_at', '<', $this->format->second($before))
            ->delete();
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function clear(): int
    {
        return $this->database->table($this->table)->where('queue', '=', $this->queue)->delete();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function hydrate(array $row): FailedMessage
    {
        return new FailedMessage(
            id: (string) $this->format->integer($row, 'id', minimum: 1),
            message: new QueuedMessage(type: $this->format->text($row, 'type'), payload: $this->format->payload($row)),
            attempt: $this->format->integer($row, 'failed_attempt', minimum: 1),
            failure: $this->hydrateFailure($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function hydrateFailure(array $row): ?Failure
    {
        if (($row['failure_type'] ?? null) !== null) {
            return new Failure(
                type: $this->format->text($row, 'failure_type'),
                message: $this->format->text($row, 'failure_message'),
                code: $this->failureCode($row),
            );
        }

        $residual = array_find(
            ['failure_message', 'failure_code_integer', 'failure_code_string'],
            static fn(string $column): bool => ($row[$column] ?? null) !== null,
        );

        return $residual === null ? null : throw $this->format->malformed($residual);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    private function failureCode(array $row): int|string
    {
        return match (true) {
            ($row['failure_code_string'] ?? null) === null => $this->format->integer(
                $row,
                'failure_code_integer',
                minimum: PHP_INT_MIN,
            ),
            ($row['failure_code_integer'] ?? null) === null => $this->format->text($row, 'failure_code_string'),
            default => throw $this->format->malformed('failure_code_string'),
        };
    }

    private function key(string $id): ?int
    {
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($key) && (string) $key === $id ? $key : null;
    }
}
