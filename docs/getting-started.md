---
id: getting-started
title: Getting started
sidebar_position: 3
description: Create the queue tables, publish a message onto a database queue, and process it with a worker.
---

This page wires a database queue together from start to finish: a database, the queue's tables, a clock, the driver,
and a worker from Dirthara Queue. Each piece has its own page with the details.

## 1. A database and a schema

The queue runs on a `Database` from Dirthara Database and creates its tables through a `Schema` from Dirthara Schema.
This wires both for PostgreSQL:

```php
use Dirthara\Database\Connection\ConnectionFactory;
use Dirthara\Database\Connection\ConnectionManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\PostgresSqlDriver;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Database;
use Dirthara\Database\Query\Grammar\PostgresSqlQueryGrammar;
use Dirthara\Database\Query\Grammar\QueryGrammarResolver;
use Dirthara\Schema\Grammar\PostgresSqlSchemaGrammar;
use Dirthara\Schema\Grammar\SchemaGrammarResolver;
use Dirthara\Schema\Schema;

$database = new Database(
    new ConnectionManager(
        new ConnectionFactory([new PostgresSqlDriver(new StandardTransactionGrammar(new SavepointPrefix()))]),
        [
            new ConnectionConfig(
                driver: DriverName::PostgresSql,
                host: 'postgres',
                database: 'app',
                username: 'app',
                password: $password,
            ),
        ],
    ),
    new QueryGrammarResolver([DriverName::PostgresSql->value => new PostgresSqlQueryGrammar()]),
);

$schema = new Schema($database, new SchemaGrammarResolver([
    DriverName::PostgresSql->value => new PostgresSqlSchemaGrammar(),
]));
```

The other databases are wired the same way with their own driver and grammars; see the documentation of
[Dirthara Database](https://github.com/dirthara/database/blob/0.2.0/docs/getting-started.md) and
[Dirthara Schema](https://github.com/dirthara/schema/blob/0.4.0/docs/installation.md).

## 2. The queue tables

```php
use Dirthara\QueueDatabase\Schema\QueueDatabaseSchema;

$queueSchema = new QueueDatabaseSchema($schema);

if (!$queueSchema->exists()) {
    $queueSchema->create();
}
```

Creating a queue never creates its tables, so this runs once, before the first message is queued: from a deploy
script, an installation command, or a migration of the application. See [schema](schema.md).

## 3. A clock

The queue reads the current time from a PSR-20 clock. Any implementation of `Psr\Clock\ClockInterface` will do:

```php
use Psr\Clock\ClockInterface;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
};
```

## 4. The queue

```php
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\QueueDriverRegistry;
use Dirthara\QueueDatabase\Driver\DatabaseQueueDriver;

$drivers = new QueueDriverRegistry();
$drivers->register('database', new DatabaseQueueDriver($database, $clock));

$queue = $drivers->create(new QueueConfiguration('database', ['queue' => 'emails']));
```

Every option has a default; see [configuration](configuration.md).

## 5. Publish and process

From here the queue is used like any queue of Dirthara Queue. Publish a message:

```php
use Dirthara\Queue\QueuedMessagePublisher;
use Dirthara\Queue\Serialiser\NativeMessageSerialiser;

$serialiser = new NativeMessageSerialiser();
$publisher = new QueuedMessagePublisher($queue, $serialiser);

$publisher->publish(new SendWelcomeEmail('ada@example.com'));
```

And process it in a worker, in this process or any other that runs on the same database:

```php
use Dirthara\Queue\Worker;

$worker = new Worker(
    queue: $queue,
    serialiser: $serialiser,
    handlers: $handlers,
    executionPolicies: $policies,
);

$worker->runOnce();
```

Handlers, execution policies, and keeping a worker running are documented in
[Dirthara Queue](https://github.com/dirthara/queue/blob/0.1/docs/getting-started.md). What the database queue does
while a worker runs is described in [how the queue works](queue-behaviour.md).

## 6. Failed messages

A message that fails for good is kept in the failed messages table, where it can be inspected, retried, or forgotten:

```php
foreach ($queue->failed() as $failed) {
    echo $failed->id, ' ', $failed->message->type, ': ', $failed->failure?->message, PHP_EOL;
}

$queue->retry($id);
```

See [failed messages](failed-messages.md).
