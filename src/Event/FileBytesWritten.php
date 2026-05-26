<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a successful File Transfer Write.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.4 OPC UA Part 5 §C.2.4
 * @see \PhpOpcua\Client\Module\FileTransfer\FileTransferModule::writeFile()
 */
readonly class FileBytesWritten
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $fileNodeId
     * @param int $fileHandle
     * @param int $bytesWritten
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $fileNodeId,
        public int $fileHandle,
        public int $bytesWritten,
    ) {
    }
}
