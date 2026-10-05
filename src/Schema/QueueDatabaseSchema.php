<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Schema;

use Dirthara\Schema\Table;
use Dirthara\Schema\Schema;
use Dirthara\Schema\ConnectedSchema;
use Dirthara\Schema\Exception\SchemaException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseSchemaException;

final readonly class QueueDatabaseSchema
{
    private ConnectedSchema $connectedSchema;

    /**
     * @throws QueueDatabaseSchemaException
     */
    public function __construct(
        private Schema $schema,
        private string $table = 'queue_messages',
        private string $failedTable = 'failed_messages',
        private ?string $connection = null,
    ) {
        try {
            $this->connectedSchema = $this->schema->using($this->connection);
        } catch (SchemaException $exception) {
            throw QueueDatabaseSchemaException::unavailableConnection(
                $this->table,
                $this->failedTable,
                $this->connection,
                $exception,
            );
        }
    }

    /**
     * @throws QueueDatabaseSchemaException
     */
    public function exists(): bool
    {
        try {
            return (
                $this->connectedSchema->hasTable($this->table) && $this->connectedSchema->hasTable($this->failedTable)
            );
        } catch (SchemaException $exception) {
            throw QueueDatabaseSchemaException::inspectFailed(
                $this->table,
                $this->failedTable,
                $this->connection,
                $exception,
            );
        }
    }

    /**
     * @throws QueueDatabaseSchemaException
     */
    public function create(): void
    {
        try {
            $this->connectedSchema->createIfNotExists($this->table, static function (Table $table): void {
                $table->id();
                $table->string('queue');
                $table->string('type');
                $table->text('payload');
                $table->integer('attempts')->unsigned();
                $table->dateTime('available_at', 3);
                $table->dateTime('created_at', 0);

                $table->index(['queue', 'available_at']);
            });

            $this->connectedSchema->createIfNotExists($this->failedTable, static function (Table $table): void {
                $table->id();
                $table->string('queue');
                $table->string('type');
                $table->text('payload');
                $table->integer('failed_attempt')->unsigned();
                $table->string('failure_type')->nullable();
                $table->text('failure_message')->nullable();
                $table->bigInteger('failure_code_integer')->nullable();
                $table->string('failure_code_string')->nullable();
                $table->dateTime('failed_at', 0);

                $table->index(['queue', 'failed_at']);
            });
        } catch (SchemaException $exception) {
            throw QueueDatabaseSchemaException::createFailed(
                $this->table,
                $this->failedTable,
                $this->connection,
                $exception,
            );
        }
    }

    /**
     * @throws QueueDatabaseSchemaException
     */
    public function drop(): void
    {
        try {
            $this->connectedSchema->dropIfExists($this->failedTable);
            $this->connectedSchema->dropIfExists($this->table);
        } catch (SchemaException $exception) {
            throw QueueDatabaseSchemaException::dropFailed(
                $this->table,
                $this->failedTable,
                $this->connection,
                $exception,
            );
        }
    }
}
