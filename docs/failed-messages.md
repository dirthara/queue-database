---
id: failed-messages
title: Failed messages
sidebar_position: 7
description: Inspect, retry, forget, purge, and clear the messages a database queue failed for good.
---

A message that is failed, usually because its retry policy gave up on it, moves from the messages table to the failed
messages table. `DatabaseQueue` implements Dirthara Queue's `FailedMessageRepository`, so it lists, retries, and forgets
them; the contract is described in
[failed messages](https://github.com/dirthara/queue/blob/0.1/docs/failed-messages.md).

## What is kept

Each failed message keeps:

| Kept | As |
| --- | --- |
| The message type and payload | Exactly as they were queued, so a retry delivers the same message. |
| The attempt that failed | `FailedMessage::$attempt`. |
| The failure's class and message | `Failure::$type` and `Failure::$message`. |
| The failure's code | `Failure::$code`, an `int` or a `string`, with the type it had. A string code such as an SQLSTATE stays a string, and a negative integer code stays negative. |

A message failed without a throwable has no failure. Neither the exception object, its trace, nor its previous
exceptions are kept.

:::danger
The payload and the failure's message are stored as they are. When either can contain personal or secret data,
protect the failed messages table the way the application protects that data, and forget failed messages once they are
dealt with.
:::

## Listing and finding

```php
foreach ($queue->failed() as $failed) {
    printf("%s  %s  attempt %d\n", $failed->id, $failed->message->type, $failed->attempt);
}

$failed = $queue->findFailed($id);
```

`failed()` returns the queue's failed messages in the order they failed. It reads them all at once, so forget, purge,
or clear failed messages that are dealt with rather than letting the table grow.

`findFailed()` returns the failed message with the id, or `null` when the queue has no failed message with that id.

A failed message's id is the key of its row, as a string such as `'42'`. Anything that is not a positive whole number
written without a sign, spaces, or leading zeros finds nothing, without querying the database.

## Retrying

```php
$queue->retry($id);
```

`retry()` moves the failed message back to the messages table as a new message: available straight away, and delivered
as attempt 1. The failure is discarded. The move happens in one transaction, so the message is never in both tables or
in neither.

## Forgetting

```php
$queue->forget($id);
```

`forget()` deletes the failed message for good.

`retry()` and `forget()` throw Dirthara Queue's `FailedMessageNotFoundException` when the queue has no failed message
with the id, including when another process retried or forgot it first.

## Purging and clearing

Two methods remove failed messages in bulk. They are not part of Dirthara Queue's contract, so call them on the
`DatabaseQueue` itself. `DatabaseQueueDriver::create()` returns one; a `QueueDriverRegistry` returns the `Queue`
contract, so code that creates queues through a registry needs the `DatabaseQueue` type for these:

```php
$removed = $queue->purgeFailed(new DateTimeImmutable('-30 days'));

$removed = $queue->clearFailed();
```

| Method | Removes | Returns |
| --- | --- | --- |
| `purgeFailed(DateTimeImmutable $before)` | The failed messages that failed before the moment. | How many it removed. |
| `clearFailed()` | Every failed message. | How many it removed. |

Both remove only the failed messages of their own queue: other queues sharing the table keep theirs, and messages
still waiting in the queue are not touched. `clearFailed()` deletes the queue's rows rather than truncating the table,
because the table can hold failed messages of other queues.

The time a message failed is kept to the second, so `purgeFailed()` compares whole seconds: a message that failed
during the same second as the moment is kept, and is removed by a purge from the next second on. The moment is
compared in UTC, whatever its time zone. The failed messages table is indexed by queue and failure time, so a purge
reads only the rows it removes.

A database failure throws a `QueueOperationException`; see [exceptions](exceptions.md).
