<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA CreateMonitoredItems operation for a single item.
 *
 * @see SubscriptionModule::createMonitoredItems()
 */
readonly class MonitoredItemResult implements WireSerializable
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

    /**
     * @return array{status: int, id: int, interval: float, queue: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->statusCode,
            'id' => $this->monitoredItemId,
            'interval' => $this->revisedSamplingInterval,
            'queue' => $this->revisedQueueSize,
        ];
    }

    /**
     * @param array{status?: int, id?: int, interval?: float, queue?: int} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(
            $data['status'] ?? 0,
            $data['id'] ?? 0,
            $data['interval'] ?? 0.0,
            $data['queue'] ?? 0,
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'MonitoredItemResult';
    }
}
