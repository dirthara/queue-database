---
id: intro
title: Dirthara QueueDatabase
sidebar_position: 1
description: A durable queue driver for Dirthara Queue that stores messages in a relational database.
---

Dirthara QueueDatabase is the durable queue driver for [Dirthara Queue](https://github.com/dirthara/queue). It keeps
queued messages and failed messages in two tables of a relational database, so a message survives the process that
published it and any number of workers, on any number of machines, can process the same queue.

It runs on MySQL, PostgreSQL, SQLite, and SQL Server through [Dirthara Database](https://github.com/dirthara/database),
and creates its tables through [Dirthara Schema](https://github.com/dirthara/schema).

| Piece | Does | Read |
| --- | --- | --- |
| `QueueDatabaseSchema` | Creates, checks, and drops the two tables a queue needs | [Schema](schema.md) |
| `DatabaseQueueDriver` | Creates a queue from a `QueueConfiguration` | [Configuration](configuration.md) |
| `DatabaseQueue` | Enqueues, reserves, and settles messages, and keeps the ones that failed | [How the queue works](queue-behaviour.md) and [Failed messages](failed-messages.md) |

The queue implements the `Queue` and `FailedMessageRepository` contracts of Dirthara Queue, so a worker processes it like
any other queue. Publishing, handlers, retry policies, and workers are documented with
[Dirthara Queue](https://github.com/dirthara/queue/blob/0.1/docs/intro.md).

See [installation](installation.md) for the requirements, then set up the [schema](schema.md).
