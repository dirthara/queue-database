<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Schema;

use Closure;
use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Schema\Grammar\SQLiteSchemaGrammar;
use Dirthara\Schema\Grammar\SchemaGrammarResolver;
use Dirthara\Database\Connection\ConnectionFactory;
use Dirthara\Database\Connection\ConnectionManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\SQLiteDriver;
use Dirthara\Schema\Exception\InvalidSchemaException;
use Dirthara\QueueDatabase\Schema\QueueDatabaseSchema;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Schema\Exception\SchemaConnectionException;
use Dirthara\Database\Query\Grammar\QueryGrammarResolver;
use Dirthara\Schema\Exception\UnsupportedDriverException;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\QueueDatabase\Exception\QueueDatabaseSchemaException;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

final class QueueDatabaseSchemaTest extends TestCase
{
    #[Test]
    public function it_refuses_a_connection_that_is_not_configured(): void
    {
        try {
            new QueueDatabaseSchema($this->schema(), connection: 'missing');
            self::fail('A queue schema was created on a connection that is not configured.');
        } catch (QueueDatabaseSchemaException $exception) {
            self::assertSame(
                ['table' => 'queue_messages', 'failed_table' => 'failed_messages', 'connection' => 'missing'],
                $exception->context,
            );
            self::assertInstanceOf(SchemaConnectionException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function it_refuses_a_default_connection_without_a_schema_grammar(): void
    {
        try {
            new QueueDatabaseSchema($this->schema(new SchemaGrammarResolver()));
            self::fail('A queue schema was created on a connection without a schema grammar.');
        } catch (QueueDatabaseSchemaException $exception) {
            self::assertNull($exception->context['connection']);
            self::assertInstanceOf(UnsupportedDriverException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function it_wraps_a_schema_failure_while_checking_whether_its_tables_exist(): void
    {
        $this->expectSchemaFailure('Unable to check whether the queue tables "queue messages" and "failed_messages" exist', static function (QueueDatabaseSchema $schema): void {
            $schema->exists();
        });
    }

    #[Test]
    public function it_wraps_a_schema_failure_while_creating_its_tables(): void
    {
        $this->expectSchemaFailure('Unable to create the queue tables "queue messages" and "failed_messages"', static function (QueueDatabaseSchema $schema): void {
            $schema->create();
        });
    }

    #[Test]
    public function it_wraps_a_schema_failure_while_dropping_its_tables(): void
    {
        $this->expectSchemaFailure('Unable to drop the queue tables "queue messages" and "failed_messages"', static function (QueueDatabaseSchema $schema): void {
            $schema->drop();
        });
    }

    /**
     * @param non-empty-string $message
     * @param Closure(QueueDatabaseSchema): void $operation
     */
    private function expectSchemaFailure(string $message, Closure $operation): void
    {
        $schema = new QueueDatabaseSchema($this->schema(), table: 'queue messages', connection: 'queues');

        try {
            $operation($schema);
            self::fail('The operation succeeded with an invalid table name.');
        } catch (QueueDatabaseSchemaException $exception) {
            self::assertStringStartsWith($message, $exception->getMessage());
            self::assertStringEndsWith(' on database connection "queues".', $exception->getMessage());
            self::assertSame(
                ['table' => 'queue messages', 'failed_table' => 'failed_messages', 'connection' => 'queues'],
                $exception->context,
            );
            self::assertInstanceOf(InvalidSchemaException::class, $exception->getPrevious());
        }
    }

    private function schema(?SchemaGrammarResolver $grammars = null): Schema
    {
        $database = new Database(
            new ConnectionManager(
                new ConnectionFactory([new SQLiteDriver(new StandardTransactionGrammar(new SavepointPrefix()))]),
                [
                    new ConnectionConfig(driver: DriverName::SQLite, database: ':memory:'),
                    new ConnectionConfig(driver: DriverName::SQLite, name: 'queues', database: ':memory:'),
                ],
            ),
            new QueryGrammarResolver([DriverName::SQLite->value => new SQLiteQueryGrammar()]),
        );

        return new Schema(
            $database,
            $grammars ?? new SchemaGrammarResolver([DriverName::SQLite->value => new SQLiteSchemaGrammar()]),
        );
    }
}
