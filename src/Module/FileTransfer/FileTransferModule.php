<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\FileTransfer;

use PhpOpcua\Client\Event\FileBytesRead;
use PhpOpcua\Client\Event\FileBytesWritten;
use PhpOpcua\Client\Event\FileClosed;
use PhpOpcua\Client\Event\FileOpened;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Client-side wrapper for the File Transfer service set.
 *
 * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C OPC UA Part 5 §C
 */
class FileTransferModule extends ServiceModule
{
    /**
     * @var array<string, array<string, NodeId>>
     */
    private array $methodCache = [];

    public function register(): void
    {
        $this->client->registerMethod('openFile', $this->openFile(...));
        $this->client->registerMethod('closeFile', $this->closeFile(...));
        $this->client->registerMethod('readFile', $this->readFile(...));
        $this->client->registerMethod('writeFile', $this->writeFile(...));
        $this->client->registerMethod('getFilePosition', $this->getFilePosition(...));
        $this->client->registerMethod('setFilePosition', $this->setFilePosition(...));
        $this->client->registerMethod('createDirectory', $this->createDirectory(...));
        $this->client->registerMethod('createFileInDirectory', $this->createFileInDirectory(...));
        $this->client->registerMethod('deleteFileSystemObject', $this->deleteFileSystemObject(...));
        $this->client->registerMethod('moveOrCopyFileSystemObject', $this->moveOrCopyFileSystemObject(...));
    }

    public function reset(): void
    {
        $this->methodCache = [];
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(CreateFileResult::class);
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param OpenFileMode|int $mode
     * @return int
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.1 OPC UA Part 5 §C.2.1
     */
    public function openFile(NodeId|string $fileNodeId, OpenFileMode|int $mode): int
    {
        $modeByte = $mode instanceof OpenFileMode ? $mode->value : $mode;
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'Open');

        $result = $this->client->call($fileNode, $methodNode, [new Variant(BuiltinType::Byte, $modeByte)]);
        $this->assertGood($result, 'Open');

        $fileHandle = (int) $result->outputArguments[0]->value;

        $this->kernel->dispatch(fn () => new FileOpened($this->client, $fileNode, $fileHandle, $modeByte));

        return $fileHandle;
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param int $fileHandle
     * @return void
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.2 OPC UA Part 5 §C.2.2
     */
    public function closeFile(NodeId|string $fileNodeId, int $fileHandle): void
    {
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'Close');

        $result = $this->client->call($fileNode, $methodNode, [new Variant(BuiltinType::UInt32, $fileHandle)]);
        $this->assertGood($result, 'Close');

        $this->kernel->dispatch(fn () => new FileClosed($this->client, $fileNode, $fileHandle));
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param int $fileHandle
     * @param int $length
     * @return string
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.3 OPC UA Part 5 §C.2.3
     */
    public function readFile(NodeId|string $fileNodeId, int $fileHandle, int $length): string
    {
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'Read');

        $result = $this->client->call($fileNode, $methodNode, [
            new Variant(BuiltinType::UInt32, $fileHandle),
            new Variant(BuiltinType::Int32, $length),
        ]);
        $this->assertGood($result, 'Read');

        $data = (string) ($result->outputArguments[0]->value ?? '');

        $this->kernel->dispatch(fn () => new FileBytesRead(
            $this->client,
            $fileNode,
            $fileHandle,
            strlen($data),
            $length,
        ));

        return $data;
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param int $fileHandle
     * @param string $data
     * @return void
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.4 OPC UA Part 5 §C.2.4
     */
    public function writeFile(NodeId|string $fileNodeId, int $fileHandle, string $data): void
    {
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'Write');

        $result = $this->client->call($fileNode, $methodNode, [
            new Variant(BuiltinType::UInt32, $fileHandle),
            new Variant(BuiltinType::ByteString, $data),
        ]);
        $this->assertGood($result, 'Write');

        $this->kernel->dispatch(fn () => new FileBytesWritten(
            $this->client,
            $fileNode,
            $fileHandle,
            strlen($data),
        ));
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param int $fileHandle
     * @return int
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.5 OPC UA Part 5 §C.2.5
     */
    public function getFilePosition(NodeId|string $fileNodeId, int $fileHandle): int
    {
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'GetPosition');

        $result = $this->client->call($fileNode, $methodNode, [new Variant(BuiltinType::UInt32, $fileHandle)]);
        $this->assertGood($result, 'GetPosition');

        return (int) $result->outputArguments[0]->value;
    }

    /**
     * @param NodeId|string $fileNodeId
     * @param int $fileHandle
     * @param int $position
     * @return void
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.2.6 OPC UA Part 5 §C.2.6
     */
    public function setFilePosition(NodeId|string $fileNodeId, int $fileHandle, int $position): void
    {
        $fileNode = $this->kernel->resolveNodeId($fileNodeId);
        $methodNode = $this->resolveMethod($fileNode, 'SetPosition');

        $result = $this->client->call($fileNode, $methodNode, [
            new Variant(BuiltinType::UInt32, $fileHandle),
            new Variant(BuiltinType::UInt64, $position),
        ]);
        $this->assertGood($result, 'SetPosition');
    }

    // FileDirectoryType wrappers — see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3

    /**
     * @param NodeId|string $directoryNodeId
     * @param string $directoryName
     * @return NodeId
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3.1 OPC UA Part 5 §C.3.1
     */
    public function createDirectory(NodeId|string $directoryNodeId, string $directoryName): NodeId
    {
        $dirNode = $this->kernel->resolveNodeId($directoryNodeId);
        $methodNode = $this->resolveMethod($dirNode, 'CreateDirectory');

        $result = $this->client->call($dirNode, $methodNode, [
            new Variant(BuiltinType::String, $directoryName),
        ]);
        $this->assertGood($result, 'CreateDirectory');

        return $this->extractNodeId($result, 'CreateDirectory');
    }

    /**
     * @param NodeId|string $directoryNodeId
     * @param string $fileName
     * @param bool $requestFileOpen
     * @return CreateFileResult
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3.2 OPC UA Part 5 §C.3.2
     */
    public function createFileInDirectory(NodeId|string $directoryNodeId, string $fileName, bool $requestFileOpen = false): CreateFileResult
    {
        $dirNode = $this->kernel->resolveNodeId($directoryNodeId);
        $methodNode = $this->resolveMethod($dirNode, 'CreateFile');

        $result = $this->client->call($dirNode, $methodNode, [
            new Variant(BuiltinType::String, $fileName),
            new Variant(BuiltinType::Boolean, $requestFileOpen),
        ]);
        $this->assertGood($result, 'CreateFile');

        if (count($result->outputArguments) < 2) {
            throw new ServiceException(
                'CreateFile returned fewer output arguments than expected',
                StatusCode::BadUnexpectedError,
            );
        }

        $newNodeId = $result->outputArguments[0]->value;
        if (! $newNodeId instanceof NodeId) {
            throw new ServiceException(
                'CreateFile output[0] is not a NodeId',
                StatusCode::BadUnexpectedError,
            );
        }
        $handle = (int) ($result->outputArguments[1]->value ?? 0);

        return new CreateFileResult($newNodeId, $handle);
    }

    /**
     * @param NodeId|string $directoryNodeId
     * @param NodeId|string $targetNodeId
     * @return void
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3.3 OPC UA Part 5 §C.3.3
     */
    public function deleteFileSystemObject(NodeId|string $directoryNodeId, NodeId|string $targetNodeId): void
    {
        $dirNode = $this->kernel->resolveNodeId($directoryNodeId);
        $targetNode = $this->kernel->resolveNodeId($targetNodeId);
        $methodNode = $this->resolveMethod($dirNode, 'DeleteFileSystemObject');

        $result = $this->client->call($dirNode, $methodNode, [
            new Variant(BuiltinType::NodeId, $targetNode),
        ]);
        $this->assertGood($result, 'DeleteFileSystemObject');
    }

    /**
     * @param NodeId|string $directoryNodeId
     * @param NodeId|string $sourceNodeId
     * @param NodeId|string $targetDirectoryNodeId
     * @param bool $createCopy
     * @param string $newName
     * @return NodeId
     *
     * @throws ServiceException
     *
     * @see https://reference.opcfoundation.org/Core/Part5/v105/docs/C.3.4 OPC UA Part 5 §C.3.4
     */
    public function moveOrCopyFileSystemObject(
        NodeId|string $directoryNodeId,
        NodeId|string $sourceNodeId,
        NodeId|string $targetDirectoryNodeId,
        bool $createCopy,
        string $newName = '',
    ): NodeId {
        $dirNode = $this->kernel->resolveNodeId($directoryNodeId);
        $srcNode = $this->kernel->resolveNodeId($sourceNodeId);
        $targetDirNode = $this->kernel->resolveNodeId($targetDirectoryNodeId);
        $methodNode = $this->resolveMethod($dirNode, 'MoveOrCopy');

        $result = $this->client->call($dirNode, $methodNode, [
            new Variant(BuiltinType::NodeId, $srcNode),
            new Variant(BuiltinType::NodeId, $targetDirNode),
            new Variant(BuiltinType::Boolean, $createCopy),
            new Variant(BuiltinType::String, $newName),
        ]);
        $this->assertGood($result, 'MoveOrCopy');

        return $this->extractNodeId($result, 'MoveOrCopy');
    }

    /**
     * @param CallResult $result
     * @param string $operation
     * @return NodeId
     *
     * @throws ServiceException
     */
    private function extractNodeId(CallResult $result, string $operation): NodeId
    {
        if (count($result->outputArguments) === 0) {
            throw new ServiceException(
                "{$operation} returned no output arguments",
                StatusCode::BadUnexpectedError,
            );
        }
        $value = $result->outputArguments[0]->value;
        if (! $value instanceof NodeId) {
            throw new ServiceException(
                "{$operation} output[0] is not a NodeId",
                StatusCode::BadUnexpectedError,
            );
        }

        return $value;
    }

    /**
     * @param NodeId $fileNode
     * @param string $methodName
     * @return NodeId
     *
     * @throws ServiceException
     */
    private function resolveMethod(NodeId $fileNode, string $methodName): NodeId
    {
        $key = (string) $fileNode;
        if (isset($this->methodCache[$key][$methodName])) {
            return $this->methodCache[$key][$methodName];
        }

        $results = $this->client->translateBrowsePaths([[
            'startingNodeId' => $fileNode,
            'relativePath' => [['targetName' => new QualifiedName(0, $methodName)]],
        ]]);

        /** @var BrowsePathResult $result */
        $result = $results[0];
        if (! StatusCode::isGood($result->statusCode) || $result->targets === []) {
            throw new ServiceException(
                "Cannot resolve File Transfer method '{$methodName}' on node {$key}: " .
                StatusCode::getName($result->statusCode),
                $result->statusCode,
            );
        }

        $methodNode = $result->targets[0]->targetId;
        $this->methodCache[$key][$methodName] = $methodNode;

        return $methodNode;
    }

    /**
     * @param CallResult $result
     * @param string $operation
     * @return void
     *
     * @throws ServiceException
     */
    private function assertGood(CallResult $result, string $operation): void
    {
        if (StatusCode::isGood($result->statusCode)) {
            return;
        }
        throw new ServiceException(
            "File Transfer {$operation} failed: " . StatusCode::getName($result->statusCode),
            $result->statusCode,
        );
    }
}
