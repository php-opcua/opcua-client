<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Exception\WriteTypeDetectionException;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\PublishResult;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\SubscriptionResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Contract for an OPC UA client capable of connecting, browsing, reading, writing, and subscribing to an OPC UA server.
 *
 * @see Client
 */
interface OpcUaClientInterface
{
    /**
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface;

    /**
     * Set the PSR-14 event dispatcher for client lifecycle and operation events.
     *
     * When set, the client dispatches granular events at key points: connection,
     * session, subscription, data change, alarms, read/write, browse, cache, and retry.
     * A {@see Event\NullEventDispatcher} is used by default,
     * ensuring zero overhead when no custom dispatcher is configured.
     *
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher to use.
     * @return self
     *
     * @see EventDispatcherInterface
     * @see Event\NullEventDispatcher
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self;

    /**
     * Get the current PSR-14 event dispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Set the trust store for server certificate validation.
     *
     * @param ?TrustStore\TrustStoreInterface $trustStore
     * @return self
     */
    public function setTrustStore(?TrustStore\TrustStoreInterface $trustStore): self;

    /**
     * Get the current trust store, or null if none configured.
     *
     * @return ?TrustStore\TrustStoreInterface
     */
    public function getTrustStore(): ?TrustStore\TrustStoreInterface;

    /**
     * Set the trust validation policy. Pass null to disable trust validation (accept all certificates).
     *
     * @param ?TrustStore\TrustPolicy $policy
     * @return self
     */
    public function setTrustPolicy(?TrustStore\TrustPolicy $policy): self;

    /**
     * Get the current trust policy. Null means validation is disabled.
     *
     * @return ?TrustStore\TrustPolicy
     */
    public function getTrustPolicy(): ?TrustStore\TrustPolicy;

    /**
     * Enable or disable auto-accept (TOFU) for unknown server certificates.
     *
     * @param bool $enabled
     * @return self
     */
    public function autoAccept(bool $enabled = true, bool $force = false): self;

    /**
     * Manually trust a server certificate and add it to the trust store.
     *
     * @param string $certDer DER-encoded certificate bytes.
     * @return void
     */
    public function trustCertificate(string $certDer): void;

    /**
     * Remove a server certificate from the trust store.
     *
     * @param string $fingerprint SHA-1 fingerprint (hex, colon-separated or plain hex).
     * @return void
     */
    public function untrustCertificate(string $fingerprint): void;

    /**
     * Return the extension object repository used for custom type decoding.
     *
     * @return ExtensionObjectRepository
     *
     * @see ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository;

    /**
     * Set the cache driver. Pass null to disable caching entirely.
     *
     * @param ?CacheInterface $cache A PSR-16 cache instance, or null to disable.
     * @return self
     *
     * @see CacheInterface
     */
    public function setCache(?CacheInterface $cache): self;

    /**
     * Get the current cache driver, or null if caching is disabled.
     *
     * @return ?CacheInterface
     */
    public function getCache(): ?CacheInterface;

    /**
     * Invalidate cached browse results for a specific node.
     *
     * @param NodeId|string $nodeId The node whose cache entries should be invalidated.
     * @return void
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     */
    public function invalidateCache(NodeId|string $nodeId): void;

    /**
     * Flush the entire cache.
     *
     * @return void
     */
    public function flushCache(): void;

    /**
     * Set the network timeout for transport operations.
     *
     * @param float $timeout Timeout in seconds.
     * @return self
     */
    public function setTimeout(float $timeout): self;

    /**
     * Get the current network timeout.
     *
     * @return float Timeout in seconds.
     */
    public function getTimeout(): float;

    /**
     * Set the maximum number of automatic reconnection retries on connection loss.
     *
     * @param int $maxRetries Maximum retry count (0 to disable).
     * @return self
     */
    public function setAutoRetry(int $maxRetries): self;

    /**
     * Get the current automatic retry count.
     *
     * @return int
     */
    public function getAutoRetry(): int;

    /**
     * Set the batch size for multi-read and multi-write operations.
     *
     * @param int $batchSize Maximum items per batch (0 to disable batching).
     * @return self
     */
    public function setBatchSize(int $batchSize): self;

    /**
     * Get the configured batch size, or null if not explicitly set.
     *
     * @return int|null
     */
    public function getBatchSize(): ?int;

    /**
     * Get the server-reported maximum nodes per read operation, or null if unknown.
     *
     * @return int|null
     */
    public function getServerMaxNodesPerRead(): ?int;

    /**
     * Get the server-reported maximum nodes per write operation, or null if unknown.
     *
     * @return int|null
     */
    public function getServerMaxNodesPerWrite(): ?int;

    /**
     * Enable or disable automatic write type detection.
     *
     * When enabled (default), write operations without an explicit type will read the node
     * first to determine the correct BuiltinType. When a type is provided explicitly,
     * it is validated against the detected type. Detected types are cached via PSR-16.
     *
     * When disabled, an explicit BuiltinType must be passed to every write call.
     *
     * @param bool $enabled Whether to enable auto-detection.
     * @return self
     */
    public function setAutoDetectWriteType(bool $enabled): self;

    /**
     * Connect to an OPC UA server endpoint.
     *
     * @param string $endpointUrl The OPC UA endpoint URL (e.g. "opc.tcp://host:4840").
     * @return void
     *
     * @throws ConfigurationException If the endpoint URL is invalid.
     * @throws ConnectionException If the TCP connection or handshake fails.
     * @throws ServiceException If a protocol-level error occurs during session creation.
     */
    public function connect(string $endpointUrl): void;

    /**
     * Reconnect to the previously connected endpoint.
     *
     * @return void
     *
     * @throws ConfigurationException If no previous endpoint exists.
     * @throws ConnectionException If the reconnection attempt fails.
     * @throws ServiceException If a protocol-level error occurs during session creation.
     */
    public function reconnect(): void;

    /**
     * Gracefully disconnect from the server, closing the session and secure channel.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Check whether the client is currently connected.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Get the current connection state.
     *
     * @return ConnectionState
     *
     * @see ConnectionState
     */
    public function getConnectionState(): ConnectionState;

    /**
     * Discover server-defined structured data types and register dynamic codecs for them.
     *
     * @param ?int $namespaceIndex Only discover types in this namespace. Null for all non-zero namespaces.
     * @param bool $useCache Whether to use the cache for this call.
     * @return int The number of types successfully discovered and registered.
     *
     * @throws ConnectionException If the connection is lost.
     * @throws ServiceException If the server returns an error.
     *
     * @see Encoding\DynamicCodec
     */
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int;

    /**
     * Discover endpoints available at the given server URL.
     *
     * @param string $endpointUrl The OPC UA endpoint URL to query.
     * @param bool $useCache Whether to use the cache for this call.
     * @return EndpointDescription[]
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see EndpointDescription
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array;

    /**
     * Browse references from a single node, returning up to one page of results.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @param bool $useCache Whether to use the browse cache for this call.
     * @return ReferenceDescription[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function browse(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
        bool $useCache = true,
    ): array;

    /**
     * Browse references from a single node, returning results with a continuation point for pagination.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return BrowseResultSet
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see BrowseResultSet
     */
    public function browseWithContinuation(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
    ): BrowseResultSet;

    /**
     * Continue a previously started browse operation using a continuation point.
     *
     * @param string $continuationPoint The opaque continuation point from a previous browse.
     * @return BrowseResultSet
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see self::browseWithContinuation()
     */
    public function browseNext(string $continuationPoint): BrowseResultSet;

    /**
     * Browse all references from a node, automatically following continuation points.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @param bool $useCache Whether to use the browse cache for this call.
     * @return ReferenceDescription[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function browseAll(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
        bool $useCache = true,
    ): array;

    /**
     * Set the default maximum depth for recursive browse operations.
     *
     * @param int $maxDepth Maximum depth (-1 for unlimited up to internal cap).
     * @return self
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self;

    /**
     * Get the default maximum depth for recursive browse operations.
     *
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int;

    /**
     * Recursively browse the address space starting from a node, building a tree of BrowseNode objects.
     *
     * @param NodeId|string $nodeId The root node to start browsing from.
     * @param BrowseDirection $direction The browse direction.
     * @param ?int $maxDepth Maximum recursion depth, or null to use the default.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return BrowseNode[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see BrowseNode
     */
    public function browseRecursive(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int $maxDepth = null,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
    ): array;

    /**
     * Translate one or more browse paths to their target NodeIds.
     *
     * @param array<array{startingNodeId: NodeId|string, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}> $browsePaths
     * @return BrowsePathResult[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see BrowsePathResult
     */
    public function translateBrowsePaths(?array $browsePaths = null): array|Builder\BrowsePathsBuilder;

    /**
     * Resolve a slash-separated browse path string to a NodeId.
     *
     * @param string $path Slash-separated browse path (e.g. "Objects/MyFolder/MyNode").
     * @param NodeId|string|null $startingNodeId Starting node, defaults to the Root node (ns=0;i=84).
     * @param bool $useCache Whether to use the browse cache for this call.
     * @return NodeId
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ServiceException If the path cannot be resolved or yields no targets.
     * @throws ConnectionException If the connection is lost during the request.
     */
    public function resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId;

    /**
     * Read a single attribute from a node.
     *
     * @param NodeId|string $nodeId The node to read.
     * @param int $attributeId The attribute to read (default 13 = Value).
     * @return DataValue
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value): DataValue;

    /**
     * Read multiple attributes from one or more nodes in a single request.
     *
     * @param array<array{nodeId: NodeId|string, attributeId?: int}> $items Items to read.
     * @return DataValue[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function readMulti(?array $readItems = null): array|Builder\ReadMultiBuilder;

    /**
     * Write a value to a node attribute.
     *
     * When no type is provided and auto-detect is enabled, the client reads the node first
     * to determine the correct BuiltinType (cached via PSR-16). When a type is provided
     * explicitly, it is used directly without any read.
     *
     * @param NodeId|string $nodeId The node to write to.
     * @param mixed $value The value to write.
     * @param ?BuiltinType $type The OPC UA built-in type, or null for auto-detection.
     * @return int The OPC UA status code for the write result.
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If the type cannot be determined (no value on node, or auto-detect disabled without explicit type).
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int;

    /**
     * Write multiple values to one or more nodes in a single request.
     *
     * When auto-detect is enabled, items without a type key will have their type resolved
     * automatically via a read (or cache). Items with a type key are validated against the node.
     *
     * @param ?array<array{nodeId: NodeId|string, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items Items to write, or null to get a fluent builder.
     * @return ($items is null ? Builder\WriteMultiBuilder : int[])
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If a type cannot be determined for an item.
     */
    public function writeMulti(?array $writeItems = null): array|Builder\WriteMultiBuilder;

    /**
     * Call a method on an object node.
     *
     * @param NodeId|string $objectId The object node that owns the method.
     * @param NodeId|string $methodId The method node to invoke.
     * @param Variant[] $inputArguments Input arguments for the method call.
     * @return CallResult
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see CallResult
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult;

    /**
     * Create a subscription for receiving data change or event notifications.
     *
     * @param float $publishingInterval Requested publishing interval in milliseconds.
     * @param int $lifetimeCount Requested lifetime count (number of publishing intervals before expiry).
     * @param int $maxKeepAliveCount Maximum keep-alive count.
     * @param int $maxNotificationsPerPublish Maximum notifications per publish response (0 = unlimited).
     * @param bool $publishingEnabled Whether publishing is initially enabled.
     * @param int $priority Relative priority of the subscription.
     * @return SubscriptionResult
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see SubscriptionResult
     */
    public function createSubscription(
        float $publishingInterval = 500.0,
        int $lifetimeCount = 2400,
        int $maxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        bool $publishingEnabled = true,
        int $priority = 0,
    ): SubscriptionResult;

    /**
     * Create monitored items within an existing subscription for data change notifications.
     *
     * @param int $subscriptionId The subscription to add items to.
     * @param array<array{nodeId: NodeId|string, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items Items to monitor.
     * @return MonitoredItemResult[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see MonitoredItemResult
     */
    public function createMonitoredItems(
        int $subscriptionId,
        ?array $items = null,
    ): array|Builder\MonitoredItemsBuilder;

    /**
     * Create a single event-based monitored item within an existing subscription.
     *
     * @param int $subscriptionId The subscription to add the item to.
     * @param NodeId|string $nodeId The node to monitor for events.
     * @param string[] $selectFields Event fields to include in notifications.
     * @param int $clientHandle Client-assigned handle for correlating notifications.
     * @return MonitoredItemResult
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see MonitoredItemResult
     */
    public function createEventMonitoredItem(
        int $subscriptionId,
        NodeId|string $nodeId,
        array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int $clientHandle = 1,
    ): MonitoredItemResult;

    /**
     * Delete monitored items from a subscription.
     *
     * @param int $subscriptionId The subscription owning the monitored items.
     * @param int[] $monitoredItemIds IDs of the monitored items to delete.
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array;

    /**
     * Delete a subscription and all its monitored items.
     *
     * @param int $subscriptionId The subscription to delete.
     * @return int The OPC UA status code for the deletion.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function deleteSubscription(int $subscriptionId): int;

    /**
     * Send a publish request to receive pending notifications from subscriptions.
     *
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements Previously received notifications to acknowledge.
     * @return PublishResult
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see PublishResult
     */
    public function publish(array $acknowledgements = []): PublishResult;

    /**
     * @param int[] $subscriptionIds
     * @param bool $sendInitialValues
     * @return Types\TransferResult[]
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array;

    /**
     * @param int $subscriptionId
     * @param int $retransmitSequenceNumber
     * @return array{sequenceNumber: int, publishTime: ?DateTimeImmutable, notifications: array}
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array;

    /**
     * Read raw historical data for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param ?DateTimeImmutable $startTime Start of the time range, or null for open-ended.
     * @param ?DateTimeImmutable $endTime End of the time range, or null for open-ended.
     * @param int $numValuesPerNode Maximum values to return (0 = server default).
     * @param bool $returnBounds Whether to include bounding values.
     * @return DataValue[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function historyReadRaw(
        NodeId|string $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
    ): array;

    /**
     * Read processed (aggregated) historical data for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param DateTimeImmutable $startTime Start of the time range.
     * @param DateTimeImmutable $endTime End of the time range.
     * @param float $processingInterval Aggregation interval in milliseconds.
     * @param NodeId $aggregateType The aggregate function NodeId (e.g. Average, Count).
     * @return DataValue[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function historyReadProcessed(
        NodeId|string $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array;

    /**
     * Read historical data at specific timestamps for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param DateTimeImmutable[] $timestamps The exact timestamps to retrieve values for.
     * @return DataValue[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function historyReadAtTime(
        NodeId|string $nodeId,
        array $timestamps,
    ): array;
}
