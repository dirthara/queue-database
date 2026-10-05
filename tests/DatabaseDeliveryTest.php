<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests;

use Throwable;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\QueueDatabase\DatabaseDelivery;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

final class DatabaseDeliveryTest extends TestCase
{
    /**
     * @var list<array{string, mixed}>
     */
    private array $settled = [];

    #[Test]
    public function it_exposes_the_message_and_its_attempt(): void
    {
        $delivery = $this->delivery();

        self::assertSame('message', $delivery->message->type);
        self::assertSame('payload', $delivery->message->payload);
        self::assertSame(3, $delivery->attempt);
    }

    #[Test]
    public function it_acknowledges_through_its_queue(): void
    {
        $this->delivery()->acknowledge();

        self::assertSame([['acknowledge', null]], $this->settled);
    }

    #[Test]
    public function it_releases_through_its_queue(): void
    {
        $delay = Duration::seconds(5);

        $this->delivery()->release($delay);

        self::assertSame([['release', $delay]], $this->settled);
    }

    #[Test]
    public function it_fails_through_its_queue(): void
    {
        $failure = new RuntimeException('Failed.');

        $this->delivery()->fail($failure);

        self::assertSame([['fail', $failure]], $this->settled);
    }

    #[Test]
    public function it_refuses_to_settle_an_acknowledged_delivery_again(): void
    {
        $delivery = $this->delivery();
        $delivery->acknowledge();

        $this->expectExceptionObject(DeliveryAlreadySettledException::alreadyAcknowledged('message'));

        $delivery->release();
    }

    #[Test]
    public function it_refuses_to_settle_a_released_delivery_again(): void
    {
        $delivery = $this->delivery();
        $delivery->release();

        $this->expectExceptionObject(DeliveryAlreadySettledException::alreadyReleased('message'));

        $delivery->fail();
    }

    #[Test]
    public function it_refuses_to_settle_a_failed_delivery_again(): void
    {
        $delivery = $this->delivery();
        $delivery->fail();

        $this->expectExceptionObject(DeliveryAlreadySettledException::alreadyFailed('message'));

        $delivery->acknowledge();
    }

    #[Test]
    public function it_stays_unsettled_when_its_queue_could_not_settle_it(): void
    {
        $refusing = new DatabaseDelivery(
            new QueuedMessage('message', 'payload'),
            1,
            static fn() => throw new RuntimeException('Reservation lost.'),
            fn(?Duration $duration) => $this->settled[] = ['release', $duration],
            fn(?Throwable $throwable) => $this->settled[] = ['fail', $throwable],
        );

        try {
            $refusing->acknowledge();
            self::fail('Acknowledging succeeded although the queue refused it.');
        } catch (RuntimeException) {
            $refusing->release();
        }

        self::assertSame([['release', null]], $this->settled);
    }

    private function delivery(): DatabaseDelivery
    {
        return new DatabaseDelivery(
            new QueuedMessage('message', 'payload'),
            3,
            fn() => $this->settled[] = ['acknowledge', null],
            fn(?Duration $duration) => $this->settled[] = ['release', $duration],
            fn(?Throwable $throwable) => $this->settled[] = ['fail', $throwable],
        );
    }
}
