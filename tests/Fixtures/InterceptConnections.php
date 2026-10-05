<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use Dirthara\Database\Connection\Connection;
use Dirthara\Database\Connection\ConnectionMiddleware;

final class InterceptConnections implements ConnectionMiddleware
{
    public private(set) ?InterceptingConnection $connection = null;

    public function wrap(Connection $connection): Connection
    {
        return $this->connection = new InterceptingConnection($connection);
    }
}
