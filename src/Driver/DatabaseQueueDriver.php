<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Driver;

use Psr\Clock\ClockInterface;
use Dirthara\Database\Database;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\QueueDatabase\DatabaseQueue;
use Dirthara\Queue\Config\QueueConfiguration;

final readonly class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private Database $database,
        private ClockInterface $clock,
    ) {}

    public function create(QueueConfiguration $configuration): Queue
    {
        $connection = $configuration->has('connection') ? $configuration->string('connection') : null;

        return new DatabaseQueue(database: $this->database->using($connection), clock: $this->clock);
    }
}
