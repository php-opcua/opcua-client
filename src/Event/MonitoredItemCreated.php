<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a monitored item has been created within a subscription.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::createMonitoredItems()
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::createEventMonitoredItem()
 */
readonly class MonitoredItemCreated
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $monitoredItemId,
        public NodeId $nodeId,
        public int $statusCode,
    ) {
    }
}
