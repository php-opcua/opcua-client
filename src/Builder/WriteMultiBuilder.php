<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Builder;

use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Fluent builder for multi-node write operations.
 *
 * @see OpcUaClientInterface::writeMulti()
 */
class WriteMultiBuilder
{
    /** @var array<array{nodeId: NodeId|string, value: mixed, type: BuiltinType}> */
    private array $items = [];
    private NodeId|string|null $currentNodeId = null;

    /**
     * Creates a new WriteMultiBuilder bound to the given client.
     *
     * @param OpcUaClientInterface $client
     */
    public function __construct(
        private readonly OpcUaClientInterface $client,
    )
    {
    }

    /**
     * Selects the target node for the next write operation.
     *
     * @param NodeId|string $nodeId
     * @return $this
     */
    public function node(NodeId|string $nodeId): self
    {
        $this->currentNodeId = $nodeId;
        return $this;
    }

    /**
     * Adds a typed value to write to the current node.
     *
     * @param mixed $value
     * @param BuiltinType $type
     * @return $this
     */
    public function typed(mixed $value, BuiltinType $type): self
    {
        $this->items[] = ['nodeId' => $this->currentNodeId, 'value' => $value, 'type' => $type];
        return $this;
    }

    /**
     * Writes a Boolean value to the current node.
     *
     * @return $this
     */
    public function boolean(bool $value): self { return $this->typed($value, BuiltinType::Boolean); }

    /**
     * Writes an SByte value to the current node.
     *
     * @return $this
     */
    public function sbyte(int $value): self { return $this->typed($value, BuiltinType::SByte); }

    /**
     * Writes a Byte value to the current node.
     *
     * @return $this
     */
    public function byte(int $value): self { return $this->typed($value, BuiltinType::Byte); }

    /**
     * Writes an Int16 value to the current node.
     *
     * @return $this
     */
    public function int16(int $value): self { return $this->typed($value, BuiltinType::Int16); }

    /**
     * Writes a UInt16 value to the current node.
     *
     * @return $this
     */
    public function uint16(int $value): self { return $this->typed($value, BuiltinType::UInt16); }

    /**
     * Writes an Int32 value to the current node.
     *
     * @return $this
     */
    public function int32(int $value): self { return $this->typed($value, BuiltinType::Int32); }

    /**
     * Writes a UInt32 value to the current node.
     *
     * @return $this
     */
    public function uint32(int $value): self { return $this->typed($value, BuiltinType::UInt32); }

    /**
     * Writes an Int64 value to the current node.
     *
     * @return $this
     */
    public function int64(int $value): self { return $this->typed($value, BuiltinType::Int64); }

    /**
     * Writes a UInt64 value to the current node.
     *
     * @return $this
     */
    public function uint64(int $value): self { return $this->typed($value, BuiltinType::UInt64); }

    /**
     * Writes a Float value to the current node.
     *
     * @return $this
     */
    public function float(float $value): self { return $this->typed($value, BuiltinType::Float); }

    /**
     * Writes a Double value to the current node.
     *
     * @return $this
     */
    public function double(float $value): self { return $this->typed($value, BuiltinType::Double); }

    /**
     * Writes a String value to the current node.
     *
     * @return $this
     */
    public function string(string $value): self { return $this->typed($value, BuiltinType::String); }

    /**
     * Executes the multi-node write and returns per-node status codes.
     *
     * @return int[]
     */
    public function execute(): array
    {
        return $this->client->writeMulti($this->items);
    }
}
