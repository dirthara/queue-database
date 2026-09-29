<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use RuntimeException;
use Dirthara\QueueDatabase\Exception\HasExceptionContext;
use Dirthara\QueueDatabase\Exception\QueueDatabaseException;

final class ContextualException extends RuntimeException implements QueueDatabaseException
{
    use HasExceptionContext;

    public static function describe(string $value): string
    {
        return self::printable($value);
    }
}
