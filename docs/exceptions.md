---
id: exceptions
title: Exceptions
sidebar_position: 7
description: Every exception Dirthara QueueDatabase throws, and when.
---

Every exception the package throws implements `Dirthara\QueueDatabase\Exception\QueueDatabaseException`, so one
`catch` handles any of them. Each also extends the SPL exception that fits it and carries a `context` array with the
values that describe the failure, such as the queue, the table, and the connection. Context and messages never contain
a payload or a failure message.

```php
use Dirthara\QueueDatabase\Exception\QueueDatabaseException;

try {
    $queue->enqueue($message);
} catch (QueueDatabaseException $exception) {
    $logger->error($exception->getMessage(), $exception->context);
}
```

An exception caused by a failure in Dirthara Database or Dirthara Schema is wrapped, with the original as its previous
exception, so code using this package never has to catch theirs.

## This package

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `QueueDatabaseConfigurationException` | `InvalidArgumentException` | `DatabaseQueueDriver` is given a connection that is not configured or has no query grammar, or a `reservation_timeout` below 1. |
| `QueueDatabaseSchemaException` | `RuntimeException` | `QueueDatabaseSchema` cannot reach its connection, or cannot check, create, or drop its tables. |
| `QueueOperationException` | `RuntimeException` | A queue operation fails in the database, a stale delivery tries to settle a message another worker reserved again, a stored row is malformed, or a message's attempt count cannot be incremented. |

A `QueueOperationException` names the operation in its message. Its context holds `queue`, `table`, and `connection`,
and, depending on the failure:

| Key | Present when |
| --- | --- |
| `id` | A failed message or a reservation is involved. |
| `attempt` | A reservation was lost; the attempt that tried to settle it. |
| `attempts` | A message's attempt count cannot be incremented. |
| `column` | A stored row is malformed; the column that failed the check. |

## From Dirthara Queue

The queue contracts define these, and a `DatabaseQueue` throws them where the contract says so:

| Exception | Thrown when |
| --- | --- |
| `FailedMessageNotFoundException` | A failed message is retried or forgotten by an id the queue has no failed message with. |
| `DeliveryAlreadySettledException` | A delivery that was already acknowledged, released, or failed is settled again. |
| `InvalidQueueConfigurationException` | An option of the queue configuration has the wrong type. |
| `InvalidDurationException` | A `reservation_timeout` is too long to express in milliseconds. |

They implement `Dirthara\Queue\Exception\QueueException` rather than this package's interface.
