<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\QueueDatabase\DatabaseQueue;
use Dirthara\Database\Connection\PdoConnection;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\SQLiteDriver;
use Dirthara\QueueDatabase\Tests\Fixtures\FrozenClock;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;
use Dirthara\QueueDatabase\Exception\QueueDatabaseConfigurationException;

final class DatabaseQueueTest extends TestCase
{
    #[Test]
    public function it_refuses_a_reservation_timeout_of_zero(): void
    {
        $this->expectExceptionObject(QueueDatabaseConfigurationException::zeroReservationTimeout());

        $this->queue(Duration::milliseconds(0));
    }

    #[Test]
    public function it_accepts_a_reservation_timeout_of_one_millisecond(): void
    {
        $this->expectNotToPerformAssertions();

        $this->queue(Duration::milliseconds(1));
    }

    private function queue(Duration $reservationTimeout): DatabaseQueue
    {
        return new DatabaseQueue(
            database: new ConnectedDatabase(
                new PdoConnection(
                    new ConnectionConfig(driver: DriverName::SQLite, database: ':memory:'),
                    new SQLiteDriver(new StandardTransactionGrammar(new SavepointPrefix())),
                ),
                new SQLiteQueryGrammar(),
            ),
            clock: new FrozenClock(new DateTimeImmutable()),
            queue: 'default',
            table: 'queue_messages',
            failedTable: 'failed_messages',
            reservationTimeout: $reservationTimeout,
        );
    }
}
