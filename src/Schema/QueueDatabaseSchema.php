<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Schema;

use Dirthara\Schema\Table;
use Dirthara\Schema\Schema;

final readonly class QueueDatabaseSchema
{
    public function __construct(
        private Schema $schema,
        private string $table = 'queue_messages',
        private string $failedTable = 'failed_messages',
        private ?string $connection = null,
    ) {}

    public function exists(): bool
    {
        return $this->schema->hasTable($this->table) && $this->schema->hasTable($this->failedTable);
    }

    public function create(): void
    {
        $this->schema->createIfNotExists(
            $this->table,
            static function (Table $table) {
                $table->id();
                $table->string('queue');
                $table->string('type');
                $table->binary('payload'); // binary?
                $table->integer('attempts')->unsigned();
                $table->dateTime('available_at')->nullable();
                $table->dateTime('reserved_until'); // do we need this in combination with available at?
                $table->timestamp('created_at');

                $table->index('available_at');
                $table->index('queue');
            },
            $this->connection,
        );

        $this->schema->createIfNotExists(
            $this->failedTable,
            static function (Table $table) {
                $table->id();
                $table->string('queue');
                $table->string('type');
                $table->binary('payload'); // binary?
                $table->string('failure_type');
                $table->text('failure_message')->nullable();
                $table->string('failure_code')->nullable();
                $table->dateTime('failed_at');

                $table->index('queue');
            },
            $this->connection,
        );
    }

    public function drop(): void
    {
        $this->schema->dropIfExists($this->failedTable, $this->connection);
        $this->schema->dropIfExists($this->table, $this->connection);
    }
}
