<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Event;

use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

/**
 * Dispatched after a monitored item has been modified via ModifyMonitoredItems.
 *
 * @see \Gianfriaur\OpcuaPhpClient\Client\ManagesSubscriptionsTrait::modifyMonitoredItems()
 */
readonly class MonitoredItemModified
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $monitoredItemId,
        public int $statusCode,
    ) {
    }
}
