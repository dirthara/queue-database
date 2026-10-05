# Security Policy

## Supported versions

| Version | Status |
| --- | --- |
| 0.1.x | Active development; unreleased |
| Older | Unsupported |

While the package is pre-1.0, only the latest release line receives fixes.

## Reporting a vulnerability

Report vulnerabilities privately using GitHub's
[Report a vulnerability](https://github.com/dirthara/queue-database/security/advisories/new)
form. Do not disclose vulnerabilities in public issues or pull requests.

Include the affected version or commit, PHP version, a minimal reproduction,
and the impact and conditions needed to trigger the issue. Maintainers will
acknowledge and assess the report. Confirmed fixes are published with an
advisory crediting the reporter unless they prefer otherwise.

## Scope

The package stores queued and failed messages in a relational database,
reserves them for workers, and settles each delivery. In scope are flaws in
that behaviour and in the package's development configuration, such as:

- a queue reading, reserving, retrying, forgetting, purging, or truncating a
  message or failed message that belongs to another queue sharing its tables;
- one attempt of a message reserved by two workers at once, a stale delivery
  settling a message that another worker reserved again, or a message lost
  instead of delivered again or kept as failed;
- a payload that comes back from the database different from how it was
  queued, or a malformed row turned into a delivery with made-up values;
- a failed message id or another caller-supplied value reaching SQL other than
  as a bound parameter;
- an exception message or context disclosing a message payload, a failure
  message, or a connection credential, or letting a queue, table, or id value
  forge a log line.

Out of scope:

- **Write access to the tables.** Whoever can write to the queue's tables can
  queue any payload. With `NativeMessageSerialiser` that payload can restore
  any class, so restrict who can write to the database; see
  [serialisation](https://github.com/dirthara/queue/blob/0.1/docs/serialisation.md)
  in Dirthara Queue.
- **Data at rest.** Payloads and failure messages are stored unencrypted. The
  base64 encoding of a payload preserves its bytes and does not protect it.
- **Configuration.** Table names, connection names, and the queue name come
  from the application's configuration and are trusted.
- **The database connection.** Credentials, TLS, and access control belong to
  the application and to `dirthara/database`.
- **Duplicate processing.** The queue delivers at least once, as documented; a
  handler running twice after a crash or an expired reservation is expected
  behaviour.

Bugs in PHP or third-party dependencies should also be reported upstream.
Application code and the sensitivity of data an application chooses to store
are the application's responsibility.
