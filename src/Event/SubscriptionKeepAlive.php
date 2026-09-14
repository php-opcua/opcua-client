<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched when a publish response contains no notifications (keep-alive).
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::publish()
 */
readonly class SubscriptionKeepAlive
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
    ) {
    }
}
