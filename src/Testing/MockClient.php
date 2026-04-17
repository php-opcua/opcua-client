<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Testing;

use DateTimeImmutable;
use PhpOpcua\Client\Builder\BrowsePathsBuilder;
use PhpOpcua\Client\Builder\MonitoredItemsBuilder;
use PhpOpcua\Client\Builder\ReadMultiBuilder;
use PhpOpcua\Client\Builder\WriteMultiBuilder;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\Subscription\MonitoredItemModifyResult;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Module\Subscription\SetTriggeringResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Repository\GeneratedTypeRegistrar;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\Variant;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * A mock OPC UA client for unit testing. Implements OpcUaClientInterface with no TCP connection.
 *
 * Register response handlers with `onRead()`, `onWrite()`, `onBrowse()`, `onCall()`, etc.
 * Track calls with `getCalls()` and assertion helpers.
 *
 * ```php
 * $mock = MockClient::create()
 *     ->onRead('i=2259', fn() => DataValue::ofInt32(0))
 *     ->onBrowse('i=85', fn() => [...]);
 * ```
 */
class MockClient implements OpcUaClientInterface
{
    /** @var array<string, callable> */
    private array $readHandlers = [];

    /** @var array<string, callable> */
    private array $writeHandlers = [];

    /** @var array<string, callable> */
    private array $browseHandlers = [];

    /** @var array<string, callable> */
    private array $callHandlers = [];

    /** @var array<string, callable> */
    private array $resolveHandlers = [];

    /** @var ?callable */
    private $endpointsHandler = null;

    /** @var array<array{method: string, args: array}> */
    private array $calls = [];

    private ConnectionState $state = ConnectionState::Disconnected;

    /** @phpstan-ignore property.onlyWritten */
    private ?string $endpointUrl = null;

    private float $timeout = 5.0;

    private int $autoRetry = 0;

    private ?int $batchSize = null;

    private int $browseMaxDepth = 10;

    private ?CacheInterface $cache = null;

    private bool $cacheInitialized = false;

    private LoggerInterface $logger;

    private EventDispatcherInterface $eventDispatcher;

    private ?TrustStoreInterface $trustStore = null;

    private ?TrustPolicy $trustPolicy = null;

    private bool $autoDetectWriteType = true;

    private ExtensionObjectRepository $repository;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->eventDispatcher = new NullEventDispatcher();
        $this->repository = new ExtensionObjectRepository();

        $this->registerDefaultBuildInfoHandlers();
    }

    /**
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * @param NodeId|string $nodeId
     * @param callable(): DataValue $handler
     * @return $this
     */
    public function onRead(NodeId|string $nodeId, callable $handler): self
    {
        $this->readHandlers[$this->key($nodeId)] = $handler;

        return $this;
    }

    /**
     * @param NodeId|string $nodeId
     * @param callable(mixed $value, BuiltinType $type): int $handler
     * @return $this
     */
    public function onWrite(NodeId|string $nodeId, callable $handler): self
    {
        $this->writeHandlers[$this->key($nodeId)] = $handler;

        return $this;
    }

    /**
     * @param NodeId|string $nodeId
     * @param callable(): ReferenceDescription[] $handler
     * @return $this
     */
    public function onBrowse(NodeId|string $nodeId, callable $handler): self
    {
        $this->browseHandlers[$this->key($nodeId)] = $handler;

        return $this;
    }

    /**
     * @param NodeId|string $objectId
     * @param NodeId|string $methodId
     * @param callable(Variant[] $args): CallResult $handler
     * @return $this
     */
    public function onCall(NodeId|string $objectId, NodeId|string $methodId, callable $handler): self
    {
        $this->callHandlers[$this->key($objectId) . '|' . $this->key($methodId)] = $handler;

        return $this;
    }

    /**
     * @param string $path
     * @param callable(): NodeId $handler
     * @return $this
     */
    public function onResolveNodeId(string $path, callable $handler): self
    {
        $this->resolveHandlers[trim($path, '/')] = $handler;

        return $this;
    }

    /**
     * @param callable(string $endpointUrl): \PhpOpcua\Client\Types\EndpointDescription[] $handler
     * @return $this
     */
    public function onGetEndpoints(callable $handler): self
    {
        $this->endpointsHandler = $handler;

        return $this;
    }

    /**
     * @return array<array{method: string, args: array}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * @param string $method
     * @return array<array{method: string, args: array}>
     */
    public function getCallsFor(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    /**
     * @param string $method
     * @return int
     */
    public function callCount(string $method): int
    {
        return count($this->getCallsFor($method));
    }

    /**
     * @return void
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }

    /**
     * @param string $endpointUrl
     * @return void
     */
    public function connect(string $endpointUrl): void
    {
        $this->record('connect', [$endpointUrl]);
        $this->endpointUrl = $endpointUrl;
        $this->state = ConnectionState::Connected;
    }

    /**
     * {@inheritDoc}
     */
    public function reconnect(): void
    {
        $this->record('reconnect', []);
        $this->state = ConnectionState::Connected;
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(): void
    {
        $this->record('disconnect', []);
        $this->state = ConnectionState::Disconnected;
        $this->endpointUrl = null;
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected(): bool
    {
        return $this->state === ConnectionState::Connected;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnectionState(): ConnectionState
    {
        return $this->state;
    }

    /**
     * {@inheritDoc}
     */
    public function hasMethod(string $name): bool
    {
        return method_exists($this, $name);
    }

    /**
     * {@inheritDoc}
     */
    public function hasModule(string $moduleClass): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerProductName(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2262), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerManufacturerName(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2263), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerSoftwareVersion(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2264), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerBuildNumber(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2265), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerBuildDate(): ?DateTimeImmutable
    {
        $value = $this->read(NodeId::numeric(0, 2266), AttributeId::Value)->getValue();

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerBuildInfo(): BuildInfo
    {
        $results = $this->readMulti([
            ['nodeId' => NodeId::numeric(0, 2262)],
            ['nodeId' => NodeId::numeric(0, 2263)],
            ['nodeId' => NodeId::numeric(0, 2264)],
            ['nodeId' => NodeId::numeric(0, 2265)],
            ['nodeId' => NodeId::numeric(0, 2266)],
        ]);

        $productName = $results[0]->getValue();
        $manufacturerName = $results[1]->getValue();
        $softwareVersion = $results[2]->getValue();
        $buildNumber = $results[3]->getValue();
        $buildDate = $results[4]->getValue();

        return new BuildInfo(
            productName: is_string($productName) ? $productName : null,
            manufacturerName: is_string($manufacturerName) ? $manufacturerName : null,
            softwareVersion: is_string($softwareVersion) ? $softwareVersion : null,
            buildNumber: is_string($buildNumber) ? $buildNumber : null,
            buildDate: $buildDate instanceof DateTimeImmutable ? $buildDate : null,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * {@inheritDoc}
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * {@inheritDoc}
     */
    public function getTrustStore(): ?TrustStoreInterface
    {
        return $this->trustStore;
    }

    /**
     * {@inheritDoc}
     */
    public function getTrustPolicy(): ?TrustPolicy
    {
        return $this->trustPolicy;
    }

    /**
     * {@inheritDoc}
     */
    public function trustCertificate(string $certDer): void
    {
        $this->record('trustCertificate', [$certDer]);
        $this->trustStore?->trust($certDer);
    }

    /**
     * {@inheritDoc}
     */
    public function untrustCertificate(string $fingerprint): void
    {
        $this->record('untrustCertificate', [$fingerprint]);
        $this->trustStore?->untrust($fingerprint);
    }

    /**
     * {@inheritDoc}
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->repository;
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * {@inheritDoc}
     */
    public function getAutoRetry(): int
    {
        return $this->autoRetry;
    }

    /**
     * {@inheritDoc}
     */
    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerMaxNodesPerRead(): ?int
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerMaxNodesPerWrite(): ?int
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultBrowseMaxDepth(): int
    {
        return $this->browseMaxDepth;
    }

    /**
     * {@inheritDoc}
     */
    public function getCache(): ?CacheInterface
    {
        if (! $this->cacheInitialized) {
            $this->cache = new InMemoryCache(300);
            $this->cacheInitialized = true;
        }

        return $this->cache;
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateCache(NodeId|string $nodeId): void
    {
        $this->record('invalidateCache', [$nodeId]);
    }

    /**
     * {@inheritDoc}
     */
    public function flushCache(): void
    {
        $this->record('flushCache', []);
        $this->getCache()?->clear();
    }

    /**
     * {@inheritDoc}
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
    {
        $this->record('read', [$nodeId, $attributeId]);
        $k = $this->key($nodeId);
        if (isset($this->readHandlers[$k])) {
            return ($this->readHandlers[$k])();
        }

        return new DataValue();
    }

    /**
     * {@inheritDoc}
     */
    public function readMulti(?array $readItems = null): array|ReadMultiBuilder
    {
        if ($readItems === null) {
            return new ReadMultiBuilder($this);
        }
        $this->record('readMulti', [$readItems]);
        $results = [];
        foreach ($readItems as $item) {
            $nodeId = $item['nodeId'];
            $results[] = $this->read($nodeId, $item['attributeId'] ?? AttributeId::Value);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
    {
        if ($type === null && $this->autoDetectWriteType) {
            $dataValue = $this->read($nodeId);
            $type = $dataValue->getVariant()?->type;
        }
        $this->record('write', [$nodeId, $value, $type]);
        $k = $this->key($nodeId);
        if (isset($this->writeHandlers[$k])) {
            return ($this->writeHandlers[$k])($value, $type);
        }

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function writeMulti(?array $writeItems = null): array|WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new WriteMultiBuilder($this);
        }
        $this->record('writeMulti', [$writeItems]);
        $results = [];
        foreach ($writeItems as $item) {
            $results[] = $this->write($item['nodeId'], $item['value'], $item['type'] ?? null);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $this->record('browse', [$nodeId, $direction]);
        $k = $this->key($nodeId);
        if (isset($this->browseHandlers[$k])) {
            return ($this->browseHandlers[$k])();
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): BrowseResultSet
    {
        $this->record('browseWithContinuation', [$nodeId]);

        return new BrowseResultSet($this->browse($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses), null);
    }

    /**
     * {@inheritDoc}
     */
    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        $this->record('browseNext', [$continuationPoint]);

        return new BrowseResultSet([], null);
    }

    /**
     * {@inheritDoc}
     */
    public function browseAll(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $this->record('browseAll', [$nodeId]);

        return $this->browse($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses);
    }

    /**
     * {@inheritDoc}
     */
    public function browseRecursive(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): array
    {
        $this->record('browseRecursive', [$nodeId, $maxDepth]);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $this->record('call', [$objectId, $methodId, $inputArguments]);
        $k = $this->key($objectId) . '|' . $this->key($methodId);
        if (isset($this->callHandlers[$k])) {
            return ($this->callHandlers[$k])($inputArguments);
        }

        return new CallResult(0, [], []);
    }

    /**
     * {@inheritDoc}
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        $this->record('getEndpoints', [$endpointUrl]);

        if ($this->endpointsHandler !== null) {
            return ($this->endpointsHandler)($endpointUrl);
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function translateBrowsePaths(?array $browsePaths = null): array|BrowsePathsBuilder
    {
        if ($browsePaths === null) {
            return new BrowsePathsBuilder($this);
        }
        $this->record('translateBrowsePaths', [$browsePaths]);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
    {
        $this->record('resolveNodeId', [$path, $startingNodeId]);
        $normalized = trim($path, '/');
        if (isset($this->resolveHandlers[$normalized])) {
            return ($this->resolveHandlers[$normalized])();
        }

        return NodeId::numeric(0, 0);
    }

    /**
     * {@inheritDoc}
     */
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        $this->record('discoverDataTypes', [$namespaceIndex]);

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0): SubscriptionResult
    {
        $this->record('createSubscription', [$publishingInterval]);

        return new SubscriptionResult(1, $publishingInterval, $lifetimeCount, $maxKeepAliveCount);
    }

    /**
     * {@inheritDoc}
     */
    public function createMonitoredItems(int $subscriptionId, ?array $monitoredItems = null): array|MonitoredItemsBuilder
    {
        if ($monitoredItems === null) {
            return new MonitoredItemsBuilder($this, $subscriptionId);
        }
        $this->record('createMonitoredItems', [$subscriptionId, $monitoredItems]);

        return array_map(fn ($i, $item) => new MonitoredItemResult(0, $i + 1, 0, 1), array_keys($monitoredItems), $monitoredItems);
    }

    /**
     * {@inheritDoc}
     */
    public function createEventMonitoredItem(int $subscriptionId, NodeId|string $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1): MonitoredItemResult
    {
        $this->record('createEventMonitoredItem', [$subscriptionId, $nodeId]);

        return new MonitoredItemResult(0, 1, 0, 1);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        $this->record('deleteMonitoredItems', [$subscriptionId, $monitoredItemIds]);

        return array_fill(0, count($monitoredItemIds), 0);
    }

    /**
     * {@inheritDoc}
     */
    public function modifyMonitoredItems(int $subscriptionId, array $itemsToModify): array
    {
        $this->record('modifyMonitoredItems', [$subscriptionId, $itemsToModify]);

        return array_map(
            fn ($item) => new MonitoredItemModifyResult(0, $item['samplingInterval'] ?? 500.0, $item['queueSize'] ?? 1),
            $itemsToModify,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = []): SetTriggeringResult
    {
        $this->record('setTriggering', [$subscriptionId, $triggeringItemId, $linksToAdd, $linksToRemove]);

        return new SetTriggeringResult(
            array_fill(0, count($linksToAdd), 0),
            array_fill(0, count($linksToRemove), 0),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        $this->record('deleteSubscription', [$subscriptionId]);

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function publish(array $acknowledgements = []): PublishResult
    {
        $this->record('publish', [$acknowledgements]);

        return new PublishResult(1, 1, false, [], []);
    }

    /**
     * {@inheritDoc}
     */
    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array
    {
        $this->record('transferSubscriptions', [$subscriptionIds, $sendInitialValues]);

        return array_map(fn ($id) => new TransferResult(0, []), $subscriptionIds);
    }

    /**
     * {@inheritDoc}
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        $this->record('republish', [$subscriptionId, $retransmitSequenceNumber]);

        return ['sequenceNumber' => $retransmitSequenceNumber, 'publishTime' => null, 'notifications' => []];
    }

    /**
     * {@inheritDoc}
     */
    public function historyReadRaw(NodeId|string $nodeId, ?DateTimeImmutable $startTime = null, ?DateTimeImmutable $endTime = null, int $numValuesPerNode = 0, bool $returnBounds = false): array
    {
        $this->record('historyReadRaw', [$nodeId]);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function historyReadProcessed(NodeId|string $nodeId, DateTimeImmutable $startTime, DateTimeImmutable $endTime, float $processingInterval, NodeId $aggregateType): array
    {
        $this->record('historyReadProcessed', [$nodeId]);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function historyReadAtTime(NodeId|string $nodeId, array $timestamps): array
    {
        $this->record('historyReadAtTime', [$nodeId]);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function addNodes(array $nodesToAdd): array
    {
        $this->record('addNodes', [$nodesToAdd]);

        return array_map(
            fn ($item) => new AddNodesResult(0, is_string($item['requestedNewNodeId']) ? NodeId::parse($item['requestedNewNodeId']) : $item['requestedNewNodeId']),
            $nodesToAdd,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodes(array $nodesToDelete): array
    {
        $this->record('deleteNodes', [$nodesToDelete]);

        return array_fill(0, count($nodesToDelete), 0);
    }

    /**
     * {@inheritDoc}
     */
    public function addReferences(array $referencesToAdd): array
    {
        $this->record('addReferences', [$referencesToAdd]);

        return array_fill(0, count($referencesToAdd), 0);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteReferences(array $referencesToDelete): array
    {
        $this->record('deleteReferences', [$referencesToDelete]);

        return array_fill(0, count($referencesToDelete), 0);
    }

    /**
     * @param ?TrustStoreInterface $trustStore
     * @return $this
     */
    public function setTrustStore(?TrustStoreInterface $trustStore): self
    {
        $this->trustStore = $trustStore;

        return $this;
    }

    /**
     * @param ?TrustPolicy $policy
     * @return $this
     */
    public function setTrustPolicy(?TrustPolicy $policy): self
    {
        $this->trustPolicy = $policy;

        return $this;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @return $this
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * @param float $timeout
     * @return $this
     */
    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @param int $maxRetries
     * @return $this
     */
    public function setAutoRetry(int $maxRetries): self
    {
        $this->autoRetry = $maxRetries;

        return $this;
    }

    /**
     * @param int $batchSize
     * @return $this
     */
    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    /**
     * @param int $maxDepth
     * @return $this
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self
    {
        $this->browseMaxDepth = $maxDepth;

        return $this;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setAutoDetectWriteType(bool $enabled): self
    {
        $this->autoDetectWriteType = $enabled;

        return $this;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setReadMetadataCache(bool $enabled): self
    {
        $this->record('setReadMetadataCache', [$enabled]);

        return $this;
    }

    /**
     * @param ?CacheInterface $cache
     * @return $this
     */
    public function setCache(?CacheInterface $cache): self
    {
        $this->cache = $cache;
        $this->cacheInitialized = true;

        return $this;
    }

    /**
     * @param GeneratedTypeRegistrar $registrar
     * @return $this
     */
    public function loadGeneratedTypes(GeneratedTypeRegistrar $registrar): self
    {
        $this->record('loadGeneratedTypes', [$registrar]);
        $registrar->registerCodecs($this->repository);

        return $this;
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function key(NodeId|string $nodeId): string
    {
        if (is_string($nodeId)) {
            return $nodeId;
        }

        return $nodeId->__toString();
    }

    private function registerDefaultBuildInfoHandlers(): void
    {
        $buildDate = new DateTimeImmutable('2026-01-01T00:00:00Z');

        $this->readHandlers['i=2262'] = fn () => DataValue::ofString('MockServer');
        $this->readHandlers['i=2263'] = fn () => DataValue::ofString('php-opcua');
        $this->readHandlers['i=2264'] = fn () => DataValue::ofString('1.0.0');
        $this->readHandlers['i=2265'] = fn () => DataValue::ofString('1');
        $this->readHandlers['i=2266'] = fn () => DataValue::ofDateTime($buildDate);
    }
}
