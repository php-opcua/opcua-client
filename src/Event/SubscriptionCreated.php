<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after a subscription has been created on the server.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::createSubscription()
 */
readonly class SubscriptionCreated
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public float $revisedPublishingInterval,
        public int $revisedLifetimeCount,
        public int $revisedMaxKeepAliveCount,
    ) {
    }
}
