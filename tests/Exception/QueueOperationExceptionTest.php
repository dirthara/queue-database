<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{QueueOperationException, string}>
     */
    public static function failedOperations(): iterable
    {
        $previous = InvalidQueryException::invalidChunkSize(0);

        yield 'reserve' => [
            QueueOperationException::reserveFailed('mail', 'jobs', 'default', $previous),
            'Unable to reserve a message from queue "mail" in table "jobs" on connection "default".',
        ];
        yield 'acknowledge' => [
            QueueOperationException::acknowledgeFailed('mail', 'jobs', 'default', $previous),
            'Unable to acknowledge a message on queue "mail" in table "jobs" on connection "default".',
        ];
        yield 'release' => [
            QueueOperationException::releaseFailed('mail', 'jobs', 'default', $previous),
            'Unable to release a message back onto queue "mail" in table "jobs" on connection "default".',
        ];
        yield 'fail' => [
            QueueOperationException::failFailed('mail', 'failed_jobs', 'default', $previous),
            'Unable to move a failed message off queue "mail" in table "failed_jobs" on connection "default".',
        ];
        yield 'read failed messages' => [
            QueueOperationException::readFailedMessagesFailed('mail', 'failed_jobs', 'default', $previous),
            'Unable to read the failed messages of queue "mail" in table "failed_jobs" on connection "default".',
        ];
        yield 'find a failed message' => [
            QueueOperationException::findFailedMessageFailed('mail', 'failed_jobs', 'default', '7', $previous),
            'Unable to find a failed message of queue "mail" in table "failed_jobs" on connection "default".',
        ];
        yield 'forget a failed message' => [
            QueueOperationException::forgetFailed('mail', 'failed_jobs', 'default', '7', $previous),
            'Unable to forget a failed message of queue "mail" in table "failed_jobs" on connection "default".',
        ];
    }

    #[Test]
    #[DataProvider('failedOperations')]
    public function it_names_the_operation_that_failed(QueueOperationException $exception, string $message): void
    {
        self::assertSame($message, $exception->getMessage());
        self::assertInstanceOf(InvalidQueryException::class, $exception->getPrevious());
        self::assertSame(['mail', 'default'], [$exception->context['queue'], $exception->context['connection']]);
    }

    #[Test]
    public function it_describes_an_attempt_counter_that_cannot_be_incremented(): void
    {
        $exception = QueueOperationException::attemptsExhausted('mail', 'jobs', 'default', 7, 2_147_483_647);

        self::assertSame(
            'Unable to reserve message 7 on queue "mail": its attempt counter of 2147483647 cannot be incremented any '
            . 'further.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['queue' => 'mail', 'table' => 'jobs', 'connection' => 'default', 'id' => 7, 'attempts' => 2_147_483_647],
            $exception->context,
        );
    }

    #[Test]
    public function it_describes_a_malformed_row_without_its_value(): void
    {
        $exception = QueueOperationException::malformedRow("mail\n", 'jobs', 'default', 'payload');

        self::assertSame(
            'Unable to read a message of queue "mail\\n" from table "jobs": column "payload" holds a malformed value.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['queue' => "mail\n", 'table' => 'jobs', 'connection' => 'default', 'column' => 'payload'],
            $exception->context,
        );
        self::assertNull($exception->getPrevious());
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
