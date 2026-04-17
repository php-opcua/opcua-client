<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\NodeManagement;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Result of an AddNodes service call for a single node.
 *
 * @see NodeManagementModule::addNodes()
 */
readonly class AddNodesResult implements WireSerializable
{
    /**
     * @param int $statusCode The OPC UA status code for this operation.
     * @param NodeId $addedNodeId The server-assigned NodeId of the newly created node.
     */
    public function __construct(
        public int $statusCode,
        public NodeId $addedNodeId,
    ) {
    }

    /**
     * @return array{status: int, added: NodeId}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->statusCode, 'added' => $this->addedNodeId];
    }

    /**
     * @param array{status?: int, added?: mixed} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['added']) || ! $data['added'] instanceof NodeId) {
            throw new EncodingException('AddNodesResult wire payload: "added" must be a decoded NodeId instance.');
        }

        return new self($data['status'] ?? 0, $data['added']);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'AddNodesResult';
    }
}
