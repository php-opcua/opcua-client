<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

/**
 * Holds the result of an OPC UA CreateMonitoredItems operation for a single item.
 *
 * @see SubscriptionModule::createMonitoredItems()
 */
readonly class MonitoredItemResult
{
    /**
     * @param int $statusCode
     * @param int $monitoredItemId
     * @param float $revisedSamplingInterval
     * @param int $revisedQueueSize
     */
    public function __construct(
        public int $statusCode,
        public int $monitoredItemId,
        public float $revisedSamplingInterval,
        public int $revisedQueueSize,
    ) {
    }
}
