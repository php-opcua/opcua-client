<?php

declare(strict_types=1);

namespace PhpOpcua\Client;

use DateTimeImmutable;
use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\InvalidNodeIdException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\WriteTypeDetectionException;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\Variant;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Contract for a connected OPC UA client capable of browsing, reading, writing, and subscribing.
 *
 * Instances are obtained via {@see ClientBuilderInterface::connect()}. This interface provides
 * read-only access to configuration and all OPC UA operations.
 *
 * @see Client
 * @see ClientBuilderInterface
 */
interface OpcUaClientInterface
{
    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface;

    /**
     * Get the current PSR-14 event dispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Get the current trust store, or null if none configured.
     *
     * @return ?TrustStoreInterface
     */
    public function getTrustStore(): ?TrustStoreInterface;

    /**
     * Get the current trust policy. Null means validation is disabled.
     *
     * @return ?TrustPolicy
     */
    public function getTrustPolicy(): ?TrustPolicy;

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
     * Get the current network timeout.
     *
     * @return float Timeout in seconds.
     */
    public function getTimeout(): float;

    /**
     * Get the current automatic retry count.
     *
     * @return int
     */
    public function getAutoRetry(): int;

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
     * Get the default maximum depth for recursive browse operations.
     *
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int;

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
     * Check whether a method is registered by any loaded module.
     *
     * @param string $name The method name.
     * @return bool
     */
    public function hasMethod(string $name): bool;

    /**
     * Check whether a module class is loaded.
     *
     * @param string $moduleClass The fully-qualified module class name.
     * @return bool
     */
    public function hasModule(string $moduleClass): bool;

    /**
     * List every method name currently reachable on this client
     * (built-in service set + any methods added by third-party modules).
     *
     * @return string[]
     */
    public function getRegisteredMethods(): array;

    /**
     * List the fully-qualified class names of every module currently loaded.
     *
     * @return class-string[]
     */
    public function getLoadedModules(): array;

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
     * Read the server's product name (ns=0;i=2262).
     *
     * @return ?string The product name, or null if the server returned no value.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function getServerProductName(): ?string;

    /**
     * Read the server's manufacturer name (ns=0;i=2263).
     *
     * @return ?string The manufacturer name, or null if the server returned no value.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function getServerManufacturerName(): ?string;

    /**
     * Read the server's software version string (ns=0;i=2264).
     *
     * @return ?string The software version, or null if the server returned no value.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function getServerSoftwareVersion(): ?string;

    /**
     * Read the server's build number (ns=0;i=2265).
     *
     * @return ?string The build number, or null if the server returned no value.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function getServerBuildNumber(): ?string;

    /**
     * Read the server's build date (ns=0;i=2266).
     *
     * @return ?DateTimeImmutable The build date, or null if the server returned no value.
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function getServerBuildDate(): ?DateTimeImmutable;

    /**
     * Read all BuildInfo fields in a single readMulti request.
     *
     * @return BuildInfo
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see BuildInfo
     */
    public function getServerBuildInfo(): BuildInfo;

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
     * @param bool $refresh Force a server read even if the result is cached.
     * @return DataValue
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue;

    /**
     * Read multiple attributes from one or more nodes in a single request.
     *
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int}> $readItems Items to read, or null to get a fluent builder.
     * @return ($readItems is null ? Builder\ReadMultiBuilder : DataValue[])
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function readMulti(?array $readItems = null): array|Builder\ReadMultiBuilder;

    /**
     * Write a value to a node attribute.
     *
     * @param NodeId|string $nodeId The node to write to.
     * @param mixed $value The value to write.
     * @param ?BuiltinType $type The OPC UA built-in type, or null for auto-detection.
     * @return int The OPC UA status code for the write result.
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If the type cannot be determined.
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int;

    /**
     * Write multiple values to one or more nodes in a single request.
     *
     * @param ?array<array{nodeId: NodeId|string, value: mixed, type?: ?BuiltinType, attributeId?: int}> $writeItems Items to write, or null to get a fluent builder.
     * @return ($writeItems is null ? Builder\WriteMultiBuilder : int[])
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
     * @param int $lifetimeCount Requested lifetime count.
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
     * Create monitored items within an existing subscription.
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
     * Modify parameters of existing monitored items.
     *
     * @param int $subscriptionId The subscription owning the monitored items.
     * @param array<array{monitoredItemId: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, discardOldest?: bool}> $itemsToModify Items to modify.
     * @return Module\Subscription\MonitoredItemModifyResult[]
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function modifyMonitoredItems(int $subscriptionId, array $itemsToModify): array;

    /**
     * Configure triggering links between monitored items.
     *
     * @param int $subscriptionId The subscription owning the items.
     * @param int $triggeringItemId The monitored item that acts as the trigger.
     * @param int[] $linksToAdd Monitored item IDs to link as triggered items.
     * @param int[] $linksToRemove Monitored item IDs to unlink.
     * @return Module\Subscription\SetTriggeringResult
     *
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = []): Module\Subscription\SetTriggeringResult;

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
     * @return Module\Subscription\TransferResult[]
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
     * @param NodeId $aggregateType The aggregate function NodeId.
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

    /**
     * Add one or more nodes to the server's address space.
     *
     * @param array<array{
     *     parentNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     requestedNewNodeId: NodeId|string,
     *     browseName: QualifiedName,
     *     nodeClass: NodeClass,
     *     typeDefinition: NodeId|string,
     *     displayName?: ?string,
     *     description?: ?string,
     *     writeMask?: int,
     *     userWriteMask?: int,
     * }> $nodesToAdd
     * @return AddNodesResult[]
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see AddNodesResult
     */
    public function addNodes(array $nodesToAdd): array;

    /**
     * Delete one or more nodes from the server's address space.
     *
     * @param array<array{nodeId: NodeId|string, deleteTargetReferences?: bool}> $nodesToDelete
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function deleteNodes(array $nodesToDelete): array;

    /**
     * Add one or more references between nodes.
     *
     * @param array<array{
     *     sourceNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     isForward: bool,
     *     targetNodeId: NodeId|string,
     *     targetNodeClass: NodeClass,
     *     targetServerUri?: ?string,
     * }> $referencesToAdd
     * @return int[] OPC UA status codes for each addition.
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function addReferences(array $referencesToAdd): array;

    /**
     * Delete one or more references between nodes.
     *
     * @param array<array{
     *     sourceNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     isForward: bool,
     *     targetNodeId: NodeId|string,
     *     deleteBidirectional?: bool,
     * }> $referencesToDelete
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     */
    public function deleteReferences(array $referencesToDelete): array;
}
