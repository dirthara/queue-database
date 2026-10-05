<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Driver;

use Psr\Clock\ClockInterface;
use Dirthara\Database\Database;
use Dirthara\Queue\Contract\Queue;
use Dirthara\Queue\Contract\QueueDriver;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\QueueDatabase\DatabaseQueue;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\Database\Exception\GrammarRegistryException;
use Dirthara\Database\Exception\ConnectionRegistryException;
use Dirthara\Queue\Exception\InvalidQueueConfigurationException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseConfigurationException;

final readonly class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private Database $database,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidQueueConfigurationException
     * @throws QueueDatabaseConfigurationException
     */
    public function create(QueueConfiguration $configuration): Queue
    {
        $connection = $configuration->has('connection') ? $configuration->string('connection') : null;
        $reservationTimeout = $configuration->int('reservation_timeout', 60);

        if ($reservationTimeout < 1) {
            throw QueueDatabaseConfigurationException::invalidReservationTimeout($reservationTimeout);
        }

        try {
            $database = $this->database->using($connection);
        } catch (ConnectionException|ConnectionRegistryException|GrammarRegistryException $exception) {
            throw QueueDatabaseConfigurationException::unavailableConnection($connection, $exception);
        }

        return new DatabaseQueue(
            database: $database,
            clock: $this->clock,
            queue: $configuration->string('queue', 'default'),
            table: $configuration->string('table', 'queue_messages'),
            failedTable: $configuration->string('failed_table', 'failed_messages'),
            reservationTimeout: Duration::seconds($reservationTimeout),
        );
    }
}
