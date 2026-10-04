<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Database\Exception\DatabaseException;

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
        return new self(
            message: sprintf('Failed to enqueue a message onto queue %s', $queue),
            previous: $previous,
            context: [
                'queue' => $queue,
                'table' => $table,
                'connection' => $connection,
            ],
        );
    }
}
