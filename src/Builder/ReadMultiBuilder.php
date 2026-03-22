<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Builder;

use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Fluent builder for multi-node read operations.
 *
 * @see OpcUaClientInterface::readMulti()
 */
class ReadMultiBuilder
{
    /** @var array<array{nodeId: NodeId|string, attributeId?: int}> */
    private array $items = [];

    /**
     * Creates a new ReadMultiBuilder bound to the given client.
     *
     * @param OpcUaClientInterface $client
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
    )
    {
    }

    /**
     * Adds a node to the read request.
     *
     * @param NodeId|string $nodeId
     * @return $this
     */
    public function node(NodeId|string $nodeId): self
    {
        $this->items[] = ['nodeId' => $nodeId];
        return $this;
    }

    /**
     * Sets the attribute to read as Value for the last added node.
     *
     * @return $this
     */
    public function value(): self
    {
        return $this->attribute(AttributeId::Value);
    }

    /**
     * Sets the attribute to read as DisplayName for the last added node.
     *
     * @return $this
     */
    public function displayName(): self
    {
        return $this->attribute(AttributeId::DisplayName);
    }

    /**
     * Sets the attribute to read as BrowseName for the last added node.
     *
     * @return $this
     */
    public function browseName(): self
    {
        return $this->attribute(AttributeId::BrowseName);
    }

    /**
     * Sets the attribute to read as NodeClass for the last added node.
     *
     * @return $this
     */
    public function nodeClass(): self
    {
        return $this->attribute(AttributeId::NodeClass);
    }

    /**
     * Sets the attribute to read as Description for the last added node.
     *
     * @return $this
     */
    public function description(): self
    {
        return $this->attribute(AttributeId::Description);
    }

    /**
     * Sets the attribute to read as DataType for the last added node.
     *
     * @return $this
     */
    public function dataType(): self
    {
        return $this->attribute(AttributeId::DataType);
    }

    /**
     * Sets a custom attribute identifier for the last added node.
     *
     * @param int $attributeId
     * @return $this
     */
    public function attribute(int $attributeId): self
    {
        if (!empty($this->items)) {
            $this->items[array_key_last($this->items)]['attributeId'] = $attributeId;
        }
        return $this;
    }

    /**
     * Executes the multi-node read and returns the results.
     *
     * @return DataValue[]
     */
    public function execute(): array
    {
        return $this->client->readMulti($this->items);
    }
}
