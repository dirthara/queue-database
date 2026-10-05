---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation of Dirthara QueueDatabase.
---

## Requirements

PHP 8.5 or later within the PHP 8 series is required, with the `pdo` extension. Composer installs the runtime
dependencies:

| Package | Provides |
| --- | --- |
| `dirthara/queue` `^0.1.0` | The queue, delivery, driver, and failed-message contracts this package implements. |
| `dirthara/database` `^0.2.0` | The connections, query builder, and transactions the queue runs on. |
| `dirthara/schema` `^0.4.0` | The table definitions `QueueDatabaseSchema` creates. |
| `psr/clock` `^1.0` | The `ClockInterface` the queue reads the current time from. |

Each database also needs its own PDO extension. Install only the one you use:

| Database | Extension |
| --- | --- |
| MySQL | `pdo_mysql` |
| PostgreSQL | `pdo_pgsql` |
| SQLite | `pdo_sqlite` |
| SQL Server | `pdo_sqlsrv` |

The package does not ship a clock. Pass any implementation of the PSR-20 `Psr\Clock\ClockInterface`.

## Package installation

Install the package with Composer:

```sh
composer require dirthara/queue-database
```

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/queue-database#readme). Development tooling includes PHPUnit, Mago, and Xdebug.
