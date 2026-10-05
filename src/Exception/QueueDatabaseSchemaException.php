<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Schema\Exception\SchemaException;

use function sprintf;

final class QueueDatabaseSchemaException extends RuntimeException implements QueueDatabaseException
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

    public static function unavailableConnection(
        string $table,
        string $failedTable,
        ?string $connection,
        SchemaException $previous,
    ): self {
        return new self(
            message: sprintf(
                'Unable to manage the queue tables "%s" and "%s": %s is unavailable.',
                self::printable($table),
                self::printable($failedTable),
                self::describeConnection($connection),
            ),
            previous: $previous,
            context: self::context($table, $failedTable, $connection),
        );
    }

    public static function inspectFailed(
        string $table,
        string $failedTable,
        ?string $connection,
        SchemaException $previous,
    ): self {
        return self::failed('check whether', 'exist', $table, $failedTable, $connection, $previous);
    }

    public static function createFailed(
        string $table,
        string $failedTable,
        ?string $connection,
        SchemaException $previous,
    ): self {
        return self::failed('create', '', $table, $failedTable, $connection, $previous);
    }

    public static function dropFailed(
        string $table,
        string $failedTable,
        ?string $connection,
        SchemaException $previous,
    ): self {
        return self::failed('drop', '', $table, $failedTable, $connection, $previous);
    }

    private static function failed(
        string $operation,
        string $suffix,
        string $table,
        string $failedTable,
        ?string $connection,
        SchemaException $previous,
    ): self {
        return new self(
            message: sprintf(
                'Unable to %s the queue tables "%s" and "%s"%s on %s.',
                $operation,
                self::printable($table),
                self::printable($failedTable),
                $suffix === '' ? '' : ' ' . $suffix,
                self::describeConnection($connection),
            ),
            previous: $previous,
            context: self::context($table, $failedTable, $connection),
        );
    }

    private static function describeConnection(?string $connection): string
    {
        return $connection === null
            ? 'the default database connection'
            : sprintf('database connection "%s"', self::printable($connection));
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(string $table, string $failedTable, ?string $connection): array
    {
        return [
            'table' => self::printable($table),
            'failed_table' => self::printable($failedTable),
            'connection' => $connection === null ? null : self::printable($connection),
        ];
    }
}
