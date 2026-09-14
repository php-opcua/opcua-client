<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after a subscription has been deleted from the server.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::deleteSubscription()
 */
readonly class SubscriptionDeleted
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $statusCode,
    ) {
    }
}
