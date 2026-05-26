<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a successful File Transfer Read.
 *
 * `$bytesRead` may be less than `$requestedLength` when the handle reaches EOF.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.3 OPC UA Part 5 §C.2.3
 * @see \PhpOpcua\Client\Module\FileTransfer\FileTransferModule::readFile()
 */
readonly class FileBytesRead
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $fileNodeId
     * @param int $fileHandle
     * @param int $bytesRead
     * @param int $requestedLength
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $fileNodeId,
        public int $fileHandle,
        public int $bytesRead,
        public int $requestedLength,
    ) {
    }
}
