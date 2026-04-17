<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\NodeManagement;

use PhpOpcua\Client\Types\NodeId;

/**
 * Result of an AddNodes service call for a single node.
 *
 * @see NodeManagementModule::addNodes()
 */
readonly class AddNodesResult
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
}
