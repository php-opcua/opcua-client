<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient;

use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

interface OpcUaClientInterface
{
    /**
     * @param float $timeout
     * @return self
     */
    public function setTimeout(float $timeout): self;

    /**
     * @return float
     */
    public function getTimeout(): float;

    /**
     * @param int $maxRetries
     * @return self
     */
    public function setAutoRetry(int $maxRetries): self;

    /**
     * @return int
     */
    public function getAutoRetry(): int;

    /**
     * @param int $batchSize 0 to disable.
     * @return self
     */
    public function setBatchSize(int $batchSize): self;

    /**
     * @return int|null
     */
    public function getBatchSize(): ?int;

    /**
     * @return int|null
     */
    public function getServerMaxNodesPerRead(): ?int;

    /**
     * @return int|null
     */
    public function getServerMaxNodesPerWrite(): ?int;

    /**
     * @param string $endpointUrl
     */
    public function connect(string $endpointUrl): void;

    public function reconnect(): void;

    public function disconnect(): void;

    public function isConnected(): bool;

    public function getConnectionState(): ConnectionState;

    /**
     * @param string $endpointUrl
     * @return EndpointDescription[]
     */
    public function getEndpoints(string $endpointUrl): array;

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browse(
        NodeId  $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool    $includeSubtypes = true,
        int     $nodeClassMask = 0,
    ): array;

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseWithContinuation(
        NodeId  $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool    $includeSubtypes = true,
        int     $nodeClassMask = 0,
    ): array;

    /**
     * @param string $continuationPoint
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseNext(string $continuationPoint): array;

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browseAll(
        NodeId  $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool    $includeSubtypes = true,
        int     $nodeClassMask = 0,
    ): array;

    /**
     * @param int $maxDepth
     * @return self
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self;

    /**
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int;

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?int $maxDepth
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return BrowseNode[]
     */
    public function browseRecursive(
        NodeId  $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int    $maxDepth = null,
        ?NodeId $referenceTypeId = null,
        bool    $includeSubtypes = true,
        int     $nodeClassMask = 0,
    ): array;

    /**
     * @param NodeId $nodeId
     * @param int $attributeId
     */
    public function read(NodeId $nodeId, int $attributeId = 13): DataValue;

    /**
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @return DataValue[]
     */
    public function readMulti(array $items): array;

    /**
     * @param NodeId $nodeId
     * @param mixed $value
     * @param BuiltinType $type
     */
    public function write(NodeId $nodeId, mixed $value, BuiltinType $type): int;

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items
     * @return int[]
     */
    public function writeMulti(array $items): array;

    /**
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @return array{statusCode: int, inputArgumentResults: int[], outputArguments: Variant[]}
     */
    public function call(NodeId $objectId, NodeId $methodId, array $inputArguments = []): array;

    /**
     * @param float $publishingInterval
     * @param int $lifetimeCount
     * @param int $maxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     * @return array{subscriptionId: int, revisedPublishingInterval: float, revisedLifetimeCount: int, revisedMaxKeepAliveCount: int}
     */
    public function createSubscription(
        float $publishingInterval = 500.0,
        int   $lifetimeCount = 2400,
        int   $maxKeepAliveCount = 10,
        int   $maxNotificationsPerPublish = 0,
        bool  $publishingEnabled = true,
        int   $priority = 0,
    ): array;

    /**
     * @param int $subscriptionId
     * @param array<array{nodeId: NodeId, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @return array<array{statusCode: int, monitoredItemId: int, revisedSamplingInterval: float, revisedQueueSize: int}>
     */
    public function createMonitoredItems(
        int   $subscriptionId,
        array $items,
    ): array;

    /**
     * @param int $subscriptionId
     * @param NodeId $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     * @return array{statusCode: int, monitoredItemId: int, revisedSamplingInterval: float, revisedQueueSize: int}
     */
    public function createEventMonitoredItem(
        int    $subscriptionId,
        NodeId $nodeId,
        array  $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int    $clientHandle = 1,
    ): array;

    /**
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     * @return int[]
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array;

    /**
     * @param int $subscriptionId
     */
    public function deleteSubscription(int $subscriptionId): int;

    /**
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     * @return array{subscriptionId: int, sequenceNumber: int, moreNotifications: bool, notifications: array, availableSequenceNumbers: int[]}
     */
    public function publish(array $acknowledgements = []): array;

    /**
     * @param NodeId $nodeId
     * @param ?\DateTimeImmutable $startTime
     * @param ?\DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     * @return DataValue[]
     */
    public function historyReadRaw(
        NodeId              $nodeId,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null,
        int                 $numValuesPerNode = 0,
        bool                $returnBounds = false,
    ): array;

    /**
     * @param NodeId $nodeId
     * @param \DateTimeImmutable $startTime
     * @param \DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     * @return DataValue[]
     */
    public function historyReadProcessed(
        NodeId             $nodeId,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        float              $processingInterval,
        NodeId             $aggregateType,
    ): array;

    /**
     * @param NodeId $nodeId
     * @param \DateTimeImmutable[] $timestamps
     * @return DataValue[]
     */
    public function historyReadAtTime(
        NodeId $nodeId,
        array  $timestamps,
    ): array;
}
