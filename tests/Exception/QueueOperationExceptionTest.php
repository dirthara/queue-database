<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Database\Exception\InvalidQueryException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;

final class QueueOperationExceptionTest extends TestCase
{
    #[Test]
    public function it_describes_a_failed_operation_with_its_queue_table_and_connection(): void
    {
        $previous = InvalidQueryException::invalidChunkSize(0);

        $exception = QueueOperationException::enqueueFailed("mail\nqueue", 'jobs', 'default', $previous);

        self::assertInstanceOf(QueueDatabaseException::class, $exception);
        self::assertSame(
            'Unable to enqueue a message onto queue "mail\\nqueue" in table "jobs" on connection "default".',
            $exception->getMessage(),
        );
        self::assertSame(['queue' => "mail\nqueue", 'table' => 'jobs', 'connection' => 'default'], $exception->context);
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function it_names_the_failed_message_an_operation_was_about(): void
    {
        $exception = QueueOperationException::retryFailed(
            'mail',
            'failed_jobs',
            'default',
            "4\n2",
            InvalidQueryException::invalidChunkSize(0),
        );

        self::assertSame(
            'Unable to retry a failed message of queue "mail" in table "failed_jobs" on connection "default".',
            $exception->getMessage(),
        );
        self::assertSame('4\\n2', $exception->context['id']);
    }

    #[Test]
    public function it_describes_a_lost_reservation(): void
    {
        $exception = QueueOperationException::reservationLost('mail', 'jobs', 'default', 7, 2);

        self::assertSame(
            'Unable to settle attempt 2 of message 7 on queue "mail": its reservation expired and the message was '
            . 'reserved again or settled elsewhere.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['queue' => 'mail', 'table' => 'jobs', 'connection' => 'default', 'id' => 7, 'attempt' => 2],
            $exception->context,
        );
    }
}
