---
id: installation
title: Installation
sidebar_position: 2
description: Requirements and installation status for Dirthara QueueDatabase.
---

## Requirements

| Requirement | Why |
| --- | --- |
| PHP 8.5 or later within the PHP 8 series | The language version every Dirthara package targets. |
| `ext-pdo` | Messages are stored and fetched through a PDO connection. |
| `dirthara/queue` `^0.1` | Defines the queue driver contract this package implements. |
| `dirthara/database` `^0.1` | Owns the connections, drivers, and PDO handling the driver runs on. |

Each database also needs its own PDO extension. Install only the ones you use:
`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, or `pdo_sqlsrv`.

## Package installation

Once published, install the package using Composer:

```sh
composer require dirthara/queue-database
```

:::caution
There is no published release yet. The command above describes the intended
installation after publication.
:::

For development, follow the Docker and Composer setup in the repository's
[README](https://github.com/dirthara/queue-database#readme). Development tooling
includes PHPUnit, Mago, and Xdebug.
