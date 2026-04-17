<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA CreateSubscription operation.
 *
 * @see SubscriptionModule::createSubscription()
 */
readonly class SubscriptionResult implements WireSerializable
{
    /**
     * @param int $subscriptionId
     * @param float $revisedPublishingInterval
     * @param int $revisedLifetimeCount
     * @param int $revisedMaxKeepAliveCount
     */
    public function __construct(
        public int $subscriptionId,
        public float $revisedPublishingInterval,
        public int $revisedLifetimeCount,
        public int $revisedMaxKeepAliveCount,
    ) {
    }

    /**
     * @return array{subId: int, interval: float, lifetime: int, keepAlive: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'subId' => $this->subscriptionId,
            'interval' => $this->revisedPublishingInterval,
            'lifetime' => $this->revisedLifetimeCount,
            'keepAlive' => $this->revisedMaxKeepAliveCount,
        ];
    }

    /**
     * @param array{subId?: int, interval?: float, lifetime?: int, keepAlive?: int} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(
            $data['subId'] ?? 0,
            $data['interval'] ?? 0.0,
            $data['lifetime'] ?? 0,
            $data['keepAlive'] ?? 0,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'SubscriptionResult';
    }
}
