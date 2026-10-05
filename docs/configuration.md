---
id: configuration
title: Configuration
sidebar_position: 5
description: Register the database queue driver and configure the queues it creates.
---

## Registering the driver

`DatabaseQueueDriver` takes the `Dirthara\Database\Database` the queues run on and the clock they read the time from.
Register it with a `QueueDriverRegistry` and create queues from a `QueueConfiguration`:

```php
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\Driver\QueueDriverRegistry;
use Dirthara\QueueDatabase\Driver\DatabaseQueueDriver;

$drivers = new QueueDriverRegistry();
$drivers->register('database', new DatabaseQueueDriver($database, $clock));

$queue = $drivers->create(new QueueConfiguration('database', [
    'queue' => 'emails',
    'reservation_timeout' => 120,
]));
```

The driver returns a `DatabaseQueue`, which implements both `Queue` and `FailedMessageRepository`. Creating a queue
does not connect to the database or create its tables; see [schema](schema.md).

## Options

| Option | Type | Default | Meaning |
| --- | --- | --- | --- |
| `connection` | `string` | the default connection | The connection of `Database` the queue runs on. |
| `queue` | `string` | `'default'` | The name of the queue within the tables. |
| `table` | `string` | `'queue_messages'` | The table of messages waiting to be processed. |
| `failed_table` | `string` | `'failed_messages'` | The table of failed messages. |
| `reservation_timeout` | `int` | `60` | Seconds a reserved message stays reserved before another worker may take it. At least 1. |

Every option is optional. An option of the wrong type throws an `InvalidQueueConfigurationException` from Dirthara
Queue, and a value is never converted: `'60'` is not a valid `reservation_timeout`.

A connection that is not configured, a connection without a query grammar, or a `reservation_timeout` below 1 throws a
`QueueDatabaseConfigurationException`. A timeout too long to express in milliseconds throws Dirthara Queue's
`InvalidDurationException`.

### Choosing a reservation timeout

The reservation timeout is how long a worker has to handle and settle a message before the queue assumes the worker is
gone and hands the message to another one. Set it comfortably above the longest time a handler takes. A timeout that is
too short lets a second worker reserve a message that the first one is still handling; see
[reservation expiry](queue-behaviour.md#reservation-expiry). A timeout that is too long delays the next attempt of a
message whose worker crashed.

## Constructing a queue directly

The driver is the usual way to create a queue, but `DatabaseQueue` can also be constructed by hand, for example in a
container that wires it without a `QueueConfiguration`:

```php
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\QueueDatabase\DatabaseQueue;

$queue = new DatabaseQueue(
    database: $database->using('queues'),
    clock: $clock,
    queue: 'emails',
    table: 'queue_messages',
    failedTable: 'failed_messages',
    reservationTimeout: Duration::seconds(120),
);
```

| Argument | Type | Meaning |
| --- | --- | --- |
| `database` | `ConnectedDatabase` | The connection the queue runs on. |
| `clock` | `ClockInterface` | The clock the queue reads the time from. |
| `queue` | `string` | The name of the queue within the tables. |
| `table` | `string` | The table of messages waiting to be processed. |
| `failedTable` | `string` | The table of failed messages. |
| `reservationTimeout` | `Duration` | How long a reserved message stays reserved. |

Every argument is required: the defaults in the table above belong to the driver.

:::caution
The constructor does not check the reservation timeout the way the driver does. A timeout below a second, and a zero
timeout in particular, lets another worker reserve a message that is still being handled.
:::

## Several queues on one pair of tables

Queues on the same tables are kept apart by their `queue` option. Each reserves, lists, retries, and forgets only its
own messages:

```php
$emails = $drivers->create(new QueueConfiguration('database', ['queue' => 'emails']));
$reports = $drivers->create(new QueueConfiguration('database', ['queue' => 'reports']));
```

A failed message id belongs to one queue as well: looking it up through another queue finds nothing.
