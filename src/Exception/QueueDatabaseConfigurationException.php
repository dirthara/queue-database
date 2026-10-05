<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Exception;

use Throwable;
use InvalidArgumentException;
use Dirthara\Database\Exception\DatabaseException;

use function sprintf;

final class QueueDatabaseConfigurationException extends InvalidArgumentException implements QueueDatabaseException
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

    public static function unavailableConnection(?string $connection, DatabaseException $previous): self
    {
        return new self(
            message: $connection === null
                ? 'Unable to create a database queue: the default database connection is unavailable.'
                : sprintf(
                    'Unable to create a database queue: database connection "%s" is unavailable.',
                    self::printable($connection),
                ),
            previous: $previous,
            context: ['connection' => $connection],
        );
    }

    public static function invalidReservationTimeout(int $seconds): self
    {
        return new self(
            message: sprintf(
                'Unable to create a database queue with a reservation timeout of %d seconds: the timeout must be at '
                . 'least 1 second, or a reserved message becomes available to another worker straight away.',
                $seconds,
            ),
            context: ['reservation_timeout' => $seconds],
        );
    }
}
