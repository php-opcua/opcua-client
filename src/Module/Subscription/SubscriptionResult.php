<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

/**
 * Holds the result of an OPC UA CreateSubscription operation.
 *
 * @see SubscriptionModule::createSubscription()
 */
readonly class SubscriptionResult
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
}
