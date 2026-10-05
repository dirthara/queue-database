<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase;

use Closure;
use Throwable;
use Dirthara\Queue\Contract\Delivery;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;

/**
 * @internal
 */
final class DatabaseDelivery implements Delivery
{
    private bool $acknowledged = false {
        get => $this->acknowledged;
    }

    private bool $released = false {
        get => $this->released;
    }

    private bool $failed = false {
        get => $this->failed;
    }

    /**
     * @param Closure(): void $acknowledge
     * @param Closure(?Duration): void $release
     * @param Closure(?Throwable): void $fail
     */
    public function __construct(
        private readonly QueuedMessage $queuedMessage,
        private readonly int $deliveryAttempt,
        private readonly Closure $acknowledge,
        private readonly Closure $release,
        private readonly Closure $fail,
    ) {}

    public QueuedMessage $message {
        get => $this->queuedMessage;
    }

    public int $attempt {
        get => $this->deliveryAttempt;
    }

    /**
     * @throws DeliveryAlreadySettledException
     */
    public function acknowledge(): void
    {
        $this->guardUnsettled();

        ($this->acknowledge)();

        $this->acknowledged = true;
    }

    /**
     * @throws DeliveryAlreadySettledException
     */
    public function release(?Duration $duration = null): void
    {
        $this->guardUnsettled();

        ($this->release)($duration);

        $this->released = true;
    }

    /**
     * @throws DeliveryAlreadySettledException
     */
    public function fail(?Throwable $throwable = null): void
    {
        $this->guardUnsettled();

        ($this->fail)($throwable);

        $this->failed = true;
    }

    /**
     * @throws DeliveryAlreadySettledException
     */
    private function guardUnsettled(): void
    {
        if ($this->acknowledged) {
            throw DeliveryAlreadySettledException::alreadyAcknowledged($this->message->type);
        }

        if ($this->released) {
            throw DeliveryAlreadySettledException::alreadyReleased($this->message->type);
        }

        if ($this->failed) {
            throw DeliveryAlreadySettledException::alreadyFailed($this->message->type);
        }
    }
}
