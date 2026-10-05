<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Schema\Grammar\SchemaGrammar;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Schema\Grammar\MySqlSchemaGrammar;
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\MySqlDriver;
use Dirthara\Database\Query\Grammar\MySqlQueryGrammar;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

#[Group('conformance')]
#[Group('integration')]
final class MySqlDatabaseQueueTest extends TestCase
{
    use DatabaseQueueConformance;

    #[Test]
    public function it_keeps_the_stored_utc_time_when_the_session_time_zone_changes(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'), Duration::milliseconds(1500));

        $this->database->execute("SET time_zone = '+05:00'");

        self::assertStringStartsWith('2026-10-05 12:00:01.750', $this->stored(
            $this->database->table(self::TABLE)->first(),
            'available_at',
        ));

        $this->clock->advance(1499);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame('message', $this->reserve()->message->type);
    }

    protected function driverName(): DriverName
    {
        return DriverName::MySql;
    }

    protected function driver(): Driver
    {
        return new MySqlDriver(new StandardTransactionGrammar(new SavepointPrefix()));
    }

    protected function schemaGrammar(): SchemaGrammar
    {
        return new MySqlSchemaGrammar();
    }

    protected function queryGrammar(): QueryGrammar
    {
        return new MySqlQueryGrammar();
    }

    protected function config(): ConnectionConfig
    {
        return new ConnectionConfig(
            driver: DriverName::MySql,
            name: 'conformance',
            host: $this->env('DIRTHARA_MYSQL_HOST', 'mysql'),
            port: (int) $this->env('DIRTHARA_MYSQL_PORT', '3306'),
            database: $this->env('DIRTHARA_MYSQL_DATABASE', 'dirthara'),
            username: $this->env('DIRTHARA_MYSQL_USERNAME', 'dirthara'),
            password: $this->env('DIRTHARA_MYSQL_PASSWORD', 'dirthara'),
        );
    }
}
