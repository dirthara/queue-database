<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function sprintf;

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(int $milliseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d milliseconds', $milliseconds));
    }
}
