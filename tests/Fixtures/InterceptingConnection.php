<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use Closure;
use Dirthara\Database\Connection\Connection;
use Dirthara\Database\Connection\Result\Result;
use Dirthara\Database\Connection\Lock\LockManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Transaction\TransactionManager;

use function str_starts_with;

final class InterceptingConnection implements Connection
{
    private ?string $statement = null;

    private ?Closure $hook = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param Closure(): void $hook
     */
    public function beforeNext(string $statement, Closure $hook): void
    {
        $this->statement = $statement;
        $this->hook = $hook;
    }

    public function execute(string $query, array $parameters = []): Result
    {
        $hook = $this->hook;

        if ($hook !== null && $this->statement !== null && str_starts_with($query, $this->statement)) {
            $this->statement = null;
            $this->hook = null;

            $hook();
        }

        return $this->connection->execute($query, $parameters);
    }

    public function lastInsertId(?string $sequence = null): ?string
    {
        return $this->connection->lastInsertId($sequence);
    }

    public function transactions(): TransactionManager
    {
        return $this->connection->transactions();
    }

    public function locks(): LockManager
    {
        return $this->connection->locks();
    }

    public function disconnect(): void
    {
        $this->connection->disconnect();
    }

    public function name(): string
    {
        return $this->connection->name();
    }

    public function driver(): DriverName
    {
        return $this->connection->driver();
    }
}
