<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after a monitored item has been deleted from a subscription.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::deleteMonitoredItems()
 */
readonly class MonitoredItemDeleted
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $monitoredItemId,
        public int $statusCode,
    ) {
    }
}
