<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Driver;

use DateTimeImmutable;
use Dirthara\Database\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\QueueDatabase\DatabaseQueue;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Database\Connection\ConnectionFactory;
use Dirthara\Database\Connection\ConnectionManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\SQLiteDriver;
use Dirthara\QueueDatabase\Driver\DatabaseQueueDriver;
use Dirthara\QueueDatabase\Tests\Fixtures\FrozenClock;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Database\Exception\GrammarRegistryException;
use Dirthara\Database\Query\Grammar\QueryGrammarResolver;
use Dirthara\Database\Exception\ConnectionRegistryException;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;
use Dirthara\QueueDatabase\Exception\QueueDatabaseConfigurationException;

final class DatabaseQueueDriverTest extends TestCase
{
    #[Test]
    public function it_creates_a_queue_on_the_default_connection(): void
    {
        $queue = $this->driver()->create(new QueueConfiguration('database'));

        self::assertInstanceOf(DatabaseQueue::class, $queue);
    }

    #[Test]
    public function it_creates_a_queue_on_a_named_connection(): void
    {
        $queue = $this->driver()->create(new QueueConfiguration('database', [
            'connection' => 'queues',
            'queue' => 'emails',
            'table' => 'jobs',
            'failed_table' => 'failed_jobs',
            'reservation_timeout' => 1,
        ]));

        self::assertInstanceOf(DatabaseQueue::class, $queue);
    }

    #[Test]
    public function it_refuses_a_reservation_timeout_below_one_second(): void
    {
        $this->expectExceptionObject(QueueDatabaseConfigurationException::invalidReservationTimeout(0));

        $this->driver()->create(new QueueConfiguration('database', ['reservation_timeout' => 0]));
    }

    #[Test]
    public function it_refuses_a_connection_that_is_not_configured(): void
    {
        try {
            $this->driver()->create(new QueueConfiguration('database', ['connection' => 'missing']));
            self::fail('A queue was created on a connection that is not configured.');
        } catch (QueueDatabaseConfigurationException $exception) {
            self::assertSame(['connection' => 'missing'], $exception->context);
            self::assertInstanceOf(ConnectionRegistryException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function it_refuses_a_default_connection_without_a_query_grammar(): void
    {
        $driver = new DatabaseQueueDriver(
            new Database($this->connections(), new QueryGrammarResolver()),
            new FrozenClock(new DateTimeImmutable()),
        );

        try {
            $driver->create(new QueueConfiguration('database'));
            self::fail('A queue was created on a connection without a query grammar.');
        } catch (QueueDatabaseConfigurationException $exception) {
            self::assertSame(['connection' => null], $exception->context);
            self::assertInstanceOf(GrammarRegistryException::class, $exception->getPrevious());
        }
    }

    private function driver(): DatabaseQueueDriver
    {
        return new DatabaseQueueDriver(new Database($this->connections(), new QueryGrammarResolver([
            DriverName::SQLite->value => new SQLiteQueryGrammar(),
        ])), new FrozenClock(new DateTimeImmutable()));
    }

    private function connections(): ConnectionManager
    {
        return new ConnectionManager(
            new ConnectionFactory([new SQLiteDriver(new StandardTransactionGrammar(new SavepointPrefix()))]),
            [
                new ConnectionConfig(driver: DriverName::SQLite, database: ':memory:'),
                new ConnectionConfig(driver: DriverName::SQLite, name: 'queues', database: ':memory:'),
            ],
        );
    }
}
