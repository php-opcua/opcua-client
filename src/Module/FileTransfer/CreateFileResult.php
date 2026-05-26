<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\FileTransfer;

use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Result of FileDirectoryType::CreateFile.
 *
 * `$fileHandle` is `0` when the caller did not request `requestFileOpen`.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3.2 OPC UA Part 5 §C.3.2
 * @see FileTransferModule::createFileInDirectory()
 */
readonly class CreateFileResult implements WireSerializable
{
    /**
     * @param NodeId $fileNodeId
     * @param int $fileHandle
     */
    public function __construct(
        public NodeId $fileNodeId,
        public int $fileHandle,
    ) {
    }

    /**
     * @return array{fileNodeId: string, fileHandle: int}
     */
    public function jsonSerialize(): array
    {
        return ['fileNodeId' => (string) $this->fileNodeId, 'fileHandle' => $this->fileHandle];
    }

    /**
     * @param array{fileNodeId?: string, fileHandle?: int} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self(NodeId::parse($data['fileNodeId'] ?? 'i=0'), $data['fileHandle'] ?? 0);
    }

    public static function wireTypeId(): string
    {
        return 'CreateFileResult';
    }
}
