---
id: queue-behaviour
title: How the queue works
sidebar_position: 6
description: Enqueueing, reserving, and settling messages, reservation expiry, delivery guarantees, and time.
---

A worker from Dirthara Queue drives a `DatabaseQueue` through the queue contract: it reserves a message, handles it, and
settles the delivery. This page describes what each step does in the database. The contract itself is described in
[queues and drivers](https://github.com/dirthara/queue/blob/0.1/docs/drivers.md).

## Enqueueing

`enqueue()` stores a message in the messages table under the queue's name, available straight away or after a delay:

```php
use Dirthara\Queue\ValueObject\Duration;

$queue->enqueue($message);
$queue->enqueue($message, Duration::seconds(30));
```

A message is stored as soon as `enqueue()` returns. The queue does not wrap it in a transaction of its own, so an
`enqueue()` inside an application's transaction on the same connection is committed or rolled back with it.

## Reserving

`reserve()` returns the queue's message that has been available the longest, or `null` when no message is available.
Messages that became available at the same moment are reserved in the order they were queued.

Reserving claims the message with a single conditional update, which only succeeds while the message still has the
attempt count and availability it was read with. When two workers reserve at the same moment, one wins the message and
the other moves on to the next available one, so a reservation never hands the same attempt to two workers. The queue
takes no row locks and needs no locking support from the database.

A reserved message stays in the messages table, hidden until the [reservation timeout](configuration.md#options) has
passed.

## Attempts

Each reservation is the next attempt of a message, counted from 1: the first reservation is attempt 1, a message
released or whose reservation expired is reserved again as attempt 2, and so on. The delivery's `attempt` is what retry
policies count.

The attempt also identifies the reservation. Settling a delivery only affects the message while it is still reserved as
that attempt, which is what stops a stale delivery from settling a message another worker has since reserved.

## Settling a delivery

| Method | Does in the database |
| --- | --- |
| `acknowledge()` | Deletes the message. |
| `release()` | Makes the message available again straight away. Its next reservation is the next attempt. |
| `release(Duration $duration)` | Makes the message available again after the duration. |
| `fail(?Throwable $throwable)` | Moves the message to the failed messages table, with a summary of the failure, in one transaction. See [failed messages](failed-messages.md). |

A delivery is settled once. Settling it again after it was settled successfully throws Dirthara Queue's
`DeliveryAlreadySettledException`. When settling fails, the exception escapes and the delivery is still unsettled, so it
can be settled again.

## Reservation expiry

When a worker stops without settling its delivery, because the process crashed or was killed, the message stays
reserved until the reservation timeout passes. Then it becomes available again and the next reservation delivers it as
the next attempt. No message is lost because its worker disappeared.

The same happens to a worker that is still running but takes longer than the timeout. Its delivery is then stale:

- If another worker has reserved the message since, the stale delivery can no longer settle it. `acknowledge()`,
  `release()`, and `fail()` throw a `QueueOperationException` saying the reservation was lost, and change nothing. The
  message belongs to the newer attempt.
- If no worker has reserved it since, the stale delivery can still settle it.

:::caution
A handler that outlives the reservation timeout runs at the same time as the next attempt of the same message. Set the
timeout above the longest time a handler needs.
:::

## Delivery guarantees

The queue delivers a message at least once. A message is removed only when a delivery acknowledges or fails it, so a
worker that stops before settling causes the message to be delivered again once its reservation expires. A message can
therefore be handled more than once, for example when a worker crashes after its handler did the work but before it
acknowledged the delivery.

The queue does not provide exactly-once processing. A handler whose work must not be repeated has to recognise a
duplicate itself, as described in Dirthara Queue's
[delivery guarantees](https://github.com/dirthara/queue/blob/0.1/docs/drivers.md#delivery-guarantees).

## Time

Every time the queue stores is an instant, read from the clock passed to the driver and stored in UTC, whatever the
time zone of the clock, the PHP process, or the database session.

- **Availability is kept to the millisecond.** A delay, a release delay, and the reservation timeout are added to the
  current time and rounded up to the next whole millisecond, so a message never becomes available before its delay
  has passed.
- **The creation and failure times are kept to the second.** They record when something happened, and nothing is
  scheduled by them.
- **Workers compare against their own clock.** A worker considers a message available when its own clock has reached
  the message's availability, so keep the clocks of the machines that run workers synchronised.

:::caution
MySQL stores a timestamp column as a `TIMESTAMP`, which cannot hold a time after 19 January 2038, 03:14:07 UTC. On
MySQL, a delay or reservation that would end after that throws a `QueueOperationException`. The other databases store
times up to the end of the year 9999; a delay beyond that is shortened to it.
:::

## Malformed rows

The queue checks every row it reads: the key and attempt count have to be integers in range, the type and payload text,
and the payload valid base64. A row that fails the check, for example after a manual edit of the table, is never turned
into a delivery with a made-up value. Reading it throws a `QueueOperationException` that names its table and column.

:::caution
A malformed row that is the next available message stops the queue: every `reserve()` on that queue throws until the
row is repaired or deleted. The same applies to a message whose attempt count has reached 2,147,483,647, the largest
attempt the column holds on every database.
:::
