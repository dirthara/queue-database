<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Database\Exception\ConnectionRegistryException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseException;
use Dirthara\QueueDatabase\Exception\QueueDatabaseConfigurationException;

final class QueueDatabaseConfigurationExceptionTest extends TestCase
{
    #[Test]
    public function it_describes_an_unavailable_named_connection(): void
    {
        $previous = ConnectionRegistryException::unknownConnection("queue\ns", []);

        $exception = QueueDatabaseConfigurationException::unavailableConnection("queue\ns", $previous);

        self::assertInstanceOf(QueueDatabaseException::class, $exception);
        self::assertSame(
            'Unable to create a database queue: database connection "queue\\ns" is unavailable.',
            $exception->getMessage(),
        );
        self::assertSame(['connection' => "queue\ns"], $exception->context);
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function it_describes_an_unavailable_default_connection(): void
    {
        $exception = QueueDatabaseConfigurationException::unavailableConnection(null, ConnectionRegistryException::unknownConnection('default', []));

        self::assertSame(
            'Unable to create a database queue: the default database connection is unavailable.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_describes_a_reservation_timeout_below_one_second(): void
    {
        $exception = QueueDatabaseConfigurationException::invalidReservationTimeout(-5);

        self::assertStringStartsWith(
            'Unable to create a database queue with a reservation timeout of -5 seconds',
            $exception->getMessage(),
        );
        self::assertSame(['reservation_timeout' => -5], $exception->context);
    }
}
