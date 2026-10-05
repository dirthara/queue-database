<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Schema\Exception\InvalidSchemaException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseSchemaException;

final class QueueDatabaseSchemaExceptionTest extends TestCase
{
    #[Test]
    public function it_describes_an_unavailable_default_connection(): void
    {
        $previous = InvalidSchemaException::emptyIdentifier();

        $exception = QueueDatabaseSchemaException::unavailableConnection("jobs\n", 'failed_jobs', null, $previous);

        self::assertInstanceOf(QueueDatabaseException::class, $exception);
        self::assertSame(
            'Unable to manage the queue tables "jobs\\n" and "failed_jobs": the default database connection is '
            . 'unavailable.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['table' => 'jobs\\n', 'failed_table' => 'failed_jobs', 'connection' => null],
            $exception->context,
        );
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function it_names_the_operation_that_failed_and_its_connection(): void
    {
        $previous = InvalidSchemaException::emptyIdentifier();

        self::assertSame(
            'Unable to check whether the queue tables "jobs" and "failed_jobs" exist on database connection '
            . '"queues\\r".',
            QueueDatabaseSchemaException::inspectFailed('jobs', 'failed_jobs', "queues\r", $previous)->getMessage(),
        );
        self::assertSame(
            'Unable to create the queue tables "jobs" and "failed_jobs" on the default database connection.',
            QueueDatabaseSchemaException::createFailed('jobs', 'failed_jobs', null, $previous)->getMessage(),
        );
        self::assertSame(
            'Unable to drop the queue tables "jobs" and "failed_jobs" on the default database connection.',
            QueueDatabaseSchemaException::dropFailed('jobs', 'failed_jobs', null, $previous)->getMessage(),
        );
    }
}
