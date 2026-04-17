<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA ModifyMonitoredItems operation for a single item.
 *
 * @see SubscriptionModule::modifyMonitoredItems()
 */
readonly class MonitoredItemModifyResult implements WireSerializable
{
    /**
     * @param int $statusCode
     * @param float $revisedSamplingInterval
     * @param int $revisedQueueSize
     */
    public function __construct(
        public int $statusCode,
        public float $revisedSamplingInterval,
        public int $revisedQueueSize,
    ) {
    }

    /**
     * @return array{status: int, interval: float, queue: int}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->statusCode, 'interval' => $this->revisedSamplingInterval, 'queue' => $this->revisedQueueSize];
    }

    /**
     * @param array{status?: int, interval?: float, queue?: int} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['status'] ?? 0, $data['interval'] ?? 0.0, $data['queue'] ?? 0);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'MonitoredItemModifyResult';
    }
}
