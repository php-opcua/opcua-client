<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Builder;

use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Fluent builder for creating monitored items within a subscription.
 *
 * @see OpcUaClientInterface::createMonitoredItems()
 */
class MonitoredItemsBuilder
{
    /** @var array<array{nodeId: NodeId|string, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int, discardOldest?: bool, filter?: array{trigger?: int, deadbandType?: int, deadbandValue?: float}}> */
    private array $items = [];

    /**
     * Creates a new MonitoredItemsBuilder for the given subscription.
     *
     * @param OpcUaClientInterface $client
     * @param int $subscriptionId
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
        private readonly int $subscriptionId,
    ) {
    }

    /**
     * Adds a node to be monitored.
     *
     * @param NodeId|string $nodeId
     * @return $this
     */
    public function add(NodeId|string $nodeId): self
    {
        $this->items[] = ['nodeId' => $nodeId];

        return $this;
    }

    /**
     * Sets the sampling interval for the last added item.
     *
     * @param float $ms
     * @return $this
     */
    public function samplingInterval(float $ms): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['samplingInterval'] = $ms;
        }

        return $this;
    }

    /**
     * Sets the queue size for the last added item.
     *
     * @param int $size
     * @return $this
     */
    public function queueSize(int $size): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['queueSize'] = $size;
        }

        return $this;
    }

    /**
     * Sets the client handle for the last added item.
     *
     * @param int $handle
     * @return $this
     */
    public function clientHandle(int $handle): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['clientHandle'] = $handle;
        }

        return $this;
    }

    /**
     * Sets the attribute identifier for the last added item.
     *
     * @param int $attributeId
     * @return $this
     */
    public function attributeId(int $attributeId): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['attributeId'] = $attributeId;
        }

        return $this;
    }

    /**
     * Sets the monitoring mode for the last added item (0 Disabled, 1 Sampling, 2 Reporting).
     *
     * @param int $mode
     * @return $this
     */
    public function monitoringMode(int $mode): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['monitoringMode'] = $mode;
        }

        return $this;
    }

    /**
     * Sets whether the oldest queued value is dropped when the queue of the last added item is full.
     *
     * @param bool $discardOldest
     * @return $this
     */
    public function discardOldest(bool $discardOldest): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['discardOldest'] = $discardOldest;
        }

        return $this;
    }

    /**
     * Sets a DataChangeFilter on the last added item.
     *
     * @param int $trigger 0 Status, 1 StatusValue, 2 StatusValueTimestamp.
     * @param int $deadbandType 0 None, 1 Absolute, 2 Percent.
     * @param float $deadbandValue Absolute units, or a percentage of the EURange for Percent.
     * @return $this
     */
    public function dataChangeFilter(int $trigger = 1, int $deadbandType = 0, float $deadbandValue = 0.0): self
    {
        if (! empty($this->items)) {
            $this->items[array_key_last($this->items)]['filter'] = [
                'trigger' => $trigger,
                'deadbandType' => $deadbandType,
                'deadbandValue' => $deadbandValue,
            ];
        }

        return $this;
    }

    /**
     * Creates the monitored items on the server and returns the results.
     *
     * @return MonitoredItemResult[]
     */
    public function execute(): array
    {
        return $this->client->createMonitoredItems($this->subscriptionId, $this->items);
    }
}
