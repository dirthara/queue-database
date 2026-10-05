---
id: schema
title: Schema
sidebar_position: 4
description: Create, check, and drop the tables a database queue stores its messages in.
---

A database queue stores its messages in two tables: one for the messages waiting to be processed, and one for the
messages that failed for good. `QueueDatabaseSchema` creates, checks, and drops them.

:::caution
Creating a queue does not create its tables. `DatabaseQueueDriver` never changes the schema, so a queue whose tables do
not exist fails on its first operation with a `QueueOperationException`. Set the tables up before the first message is
queued.
:::

## Setting up the tables

`QueueDatabaseSchema` takes a `Dirthara\Schema\Schema`, the names of the two tables, and the connection to create them
on:

```php
use Dirthara\QueueDatabase\Schema\QueueDatabaseSchema;

$queueSchema = new QueueDatabaseSchema($schema);

if (!$queueSchema->exists()) {
    $queueSchema->create();
}
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `schema` | `Schema` | | The schema the tables are created through. |
| `table` | `string` | `'queue_messages'` | The table of messages waiting to be processed. |
| `failedTable` | `string` | `'failed_messages'` | The table of failed messages. |
| `connection` | `?string` | `null` | The connection to create the tables on; `null` is the default connection. |

Use the same table names and connection as the [queue configuration](configuration.md), or the queue looks for its
messages somewhere else.

| Method | Does |
| --- | --- |
| `exists(): bool` | Returns whether both tables exist. |
| `create(): void` | Creates whichever of the two tables does not exist yet, and leaves an existing one as it is. |
| `drop(): void` | Drops both tables, with every message and failed message in them. Missing tables are skipped. |

`create()` never changes a table that already exists, so it is safe to call on every deploy. It also never upgrades one:
a table created by an older version of this package keeps its old columns.

A failure of any of them, including a connection that is not configured, throws a `QueueDatabaseSchemaException`; see
[exceptions](exceptions.md).

## When the tables are created

The package leaves that decision to the application. It does not depend on `dirthara/migration` and ships no migration:
call `create()` from a deploy script, from an installation command, or from a migration the application writes itself.
A framework integration may wrap `QueueDatabaseSchema` in a migration of its own, but that is not part of this package.

## Sharing tables between queues

One pair of tables holds any number of queues. Each message records the name of the queue it belongs to, and a queue
only ever sees its own messages, so several queues with different names can be configured on the same tables. See
[configuration](configuration.md).

## The tables

The messages table:

| Column | Holds |
| --- | --- |
| `id` | The message's key. |
| `queue` | The name of the queue it belongs to. |
| `type` | The message type recorded by the serialiser. |
| `payload` | The serialised message, base64 encoded. |
| `attempts` | How often the message has been reserved. |
| `available_at` | When the message can next be reserved, to the millisecond. |
| `created_at` | When it was first queued. |

The failed messages table:

| Column | Holds |
| --- | --- |
| `id` | The failed message's key, which is its id. |
| `queue` | The name of the queue it failed on. |
| `type` | The message type. |
| `payload` | The serialised message, base64 encoded. |
| `failed_attempt` | The attempt that failed. |
| `failure_type`, `failure_message` | The failure's class and message, or `null` when it was failed without one. |
| `failure_code_integer`, `failure_code_string` | The failure's code, in whichever column matches its type. |
| `failed_at` | When it failed. |

The payload is base64 encoded because a serialised PHP object holds bytes, such as the null bytes around a private
property's name, that PostgreSQL and SQL Server refuse in a text value. Every payload comes back exactly as it was
queued.
