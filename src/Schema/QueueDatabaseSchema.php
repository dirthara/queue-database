<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Schema;

use Dirthara\Schema\Table;
use Dirthara\Schema\Schema;
use Dirthara\Schema\ConnectedSchema;

final readonly class QueueDatabaseSchema
{
    private ConnectedSchema $connectedSchema;

    public function __construct(
        private Schema $schema,
        private string $table = 'queue_messages',
        private string $failedTable = 'failed_messages',
        private ?string $connection = null,
    ) {
        $this->connectedSchema = $this->schema->using($this->connection);
    }

    public function exists(): bool
    {
        return $this->connectedSchema->hasTable($this->table) && $this->connectedSchema->hasTable($this->failedTable);
    }

    public function create(): void
    {
        $this->connectedSchema->createIfNotExists($this->table, static function (Table $table) {
            $table->id();
            $table->string('queue');
            $table->string('type');
            $table->binary('payload'); // binary?
            $table->integer('attempts')->unsigned();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['queue', 'available_at']);
        });

        $this->connectedSchema->createIfNotExists($this->failedTable, static function (Table $table) {
            $table->id();
            $table->string('queue');
            $table->string('type');
            $table->binary('payload'); // binary?
            $table->integer('failed_attempt')->unsigned();
            $table->string('failure_type')->nullable();
            $table->text('failure_message')->nullable();
            $table->string('failure_code')->nullable();
            $table->dateTime('failed_at');

            $table->index('queue');
        });
    }

    public function drop(): void
    {
        $this->connectedSchema->dropIfExists($this->failedTable);
        $this->connectedSchema->dropIfExists($this->table);
    }
}
