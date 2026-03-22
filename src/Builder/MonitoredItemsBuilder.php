<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Builder;

use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Fluent builder for creating monitored items within a subscription.
 *
 * @see OpcUaClientInterface::createMonitoredItems()
 */
class MonitoredItemsBuilder
{
    /** @var array<array{nodeId: NodeId|string, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> */
    private array $items = [];

    /**
     * Creates a new MonitoredItemsBuilder for the given subscription.
     *
     * @param OpcUaClientInterface $client
     * @param int $subscriptionId
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
        private readonly int                  $subscriptionId,
    )
    {
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
        if (!empty($this->items)) {
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
        if (!empty($this->items)) {
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
        if (!empty($this->items)) {
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
        if (!empty($this->items)) {
            $this->items[array_key_last($this->items)]['attributeId'] = $attributeId;
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
