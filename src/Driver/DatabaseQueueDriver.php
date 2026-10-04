<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Driver;

use Dirthara\Database\Database;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\Queue\Config\QueueConfiguration;

final readonly class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private Database $database, // Database or ConnectedDatabase?
    ) {}

    public function create(QueueConfiguration $configuration): Queue
    {
        // TODO: Implement create() method.
        // validate options
        // resolve the connection
        // create DatabaseQueue
    }
}
