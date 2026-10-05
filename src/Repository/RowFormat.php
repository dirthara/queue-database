<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Repository;

use DateTimeZone;
use DateTimeImmutable;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

use function is_int;
use function is_string;
use function preg_match;
use function base64_decode;
use function base64_encode;

/**
 * @internal
 */
final readonly class RowFormat
{
    private const string INTEGER_PATTERN = '/^-?(?:0|[1-9][0-9]*)$/';

    public function __construct(
        private ConnectedDatabase $database,
        private string $queue,
        private string $table,
    ) {}

    public function instant(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public function second(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function encode(string $payload): string
    {
        return base64_encode($payload);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    public function integer(array $row, string $column, int $minimum): int
    {
        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;

        $integer = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match(self::INTEGER_PATTERN, $value) === 1 && (string) (int) $value === $value
                => (int) $value,
            default => null,
        };

        return $integer !== null && $integer >= $minimum ? $integer : throw $this->malformed($column);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    public function text(array $row, string $column): string
    {
        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : throw $this->malformed($column);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws QueueOperationException
     */
    public function payload(array $row): string
    {
        $encoded = $this->text($row, 'payload');
        $payload = base64_decode($encoded, strict: true);

        return $payload !== false && base64_encode($payload) === $encoded
            ? $payload
            : throw $this->malformed('payload');
    }

    public function malformed(string $column): QueueOperationException
    {
        return QueueOperationException::malformedRow(
            $this->queue,
            $this->table,
            $this->database->connection()->name(),
            $column,
        );
    }
}
