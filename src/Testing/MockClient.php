<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Testing;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\MonitoredItemsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Cache\InMemoryCache;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Psr\SimpleCache\CacheInterface;
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
use Gianfriaur\OpcuaPhpClient\Types\TransferResult;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\SubscriptionResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    /** @var array<array{method: string, args: array}> */
    private array $calls = [];

    private ConnectionState $state = ConnectionState::Disconnected;
    private ?string $endpointUrl = null;
    private float $timeout = 5.0;
    private int $autoRetry = 0;
    private ?int $batchSize = null;
    private int $browseMaxDepth = 10;
    private ?CacheInterface $cache = null;
    private bool $cacheInitialized = false;
    private LoggerInterface $logger;
    private ExtensionObjectRepository $repository;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->repository = new ExtensionObjectRepository();
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
        return array_values(array_filter($this->calls, fn($c) => $c['method'] === $method));
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
    public function connect(string $endpointUrl): void
    {
        $this->record('connect', [$endpointUrl]);
        $this->endpointUrl = $endpointUrl;
        $this->state = ConnectionState::Connected;
    }

    public function reconnect(): void
    {
        $this->record('reconnect', []);
        $this->state = ConnectionState::Connected;
    }

    public function disconnect(): void
    {
        $this->record('disconnect', []);
        $this->state = ConnectionState::Disconnected;
        $this->endpointUrl = null;
    }

    public function isConnected(): bool { return $this->state === ConnectionState::Connected; }
    public function getConnectionState(): ConnectionState { return $this->state; }
    public function setLogger(LoggerInterface $logger): self { $this->logger = $logger; return $this; }
    public function getLogger(): LoggerInterface { return $this->logger; }
    public function getExtensionObjectRepository(): ExtensionObjectRepository { return $this->repository; }
    public function setTimeout(float $timeout): self { $this->timeout = $timeout; return $this; }
    public function getTimeout(): float { return $this->timeout; }
    public function setAutoRetry(int $maxRetries): self { $this->autoRetry = $maxRetries; return $this; }
    public function getAutoRetry(): int { return $this->autoRetry; }
    public function setBatchSize(int $batchSize): self { $this->batchSize = $batchSize; return $this; }
    public function getBatchSize(): ?int { return $this->batchSize; }
    public function getServerMaxNodesPerRead(): ?int { return null; }
    public function getServerMaxNodesPerWrite(): ?int { return null; }
    public function setDefaultBrowseMaxDepth(int $maxDepth): self { $this->browseMaxDepth = $maxDepth; return $this; }
    public function getDefaultBrowseMaxDepth(): int { return $this->browseMaxDepth; }
    public function setCache(?CacheInterface $cache): self { $this->cache = $cache; $this->cacheInitialized = true; return $this; }
    public function getCache(): ?CacheInterface { if (!$this->cacheInitialized) { $this->cache = new InMemoryCache(300); $this->cacheInitialized = true; } return $this->cache; }
    public function invalidateCache(NodeId|string $nodeId): void { $this->record('invalidateCache', [$nodeId]); }
    public function flushCache(): void { $this->record('flushCache', []); $this->getCache()?->clear(); }
    public function read(NodeId|string $nodeId, int $attributeId = 13): DataValue
    {
        $this->record('read', [$nodeId, $attributeId]);
        $k = $this->key($nodeId);
        if (isset($this->readHandlers[$k])) {
            return ($this->readHandlers[$k])();
        }
        return new DataValue();
    }

    public function readMulti(?array $readItems = null): array|ReadMultiBuilder
    {
        if ($readItems === null) {
            return new ReadMultiBuilder($this);
        }
        $this->record('readMulti', [$readItems]);
        $results = [];
        foreach ($readItems as $item) {
            $nodeId = $item['nodeId'];
            $results[] = $this->read($nodeId, $item['attributeId'] ?? 13);
        }
        return $results;
    }

    public function write(NodeId|string $nodeId, mixed $value, BuiltinType $type): int
    {
        $this->record('write', [$nodeId, $value, $type]);
        $k = $this->key($nodeId);
        if (isset($this->writeHandlers[$k])) {
            return ($this->writeHandlers[$k])($value, $type);
        }
        return 0;
    }

    public function writeMulti(?array $writeItems = null): array|WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new WriteMultiBuilder($this);
        }
        $this->record('writeMulti', [$writeItems]);
        $results = [];
        foreach ($writeItems as $item) {
            $results[] = $this->write($item['nodeId'], $item['value'], $item['type']);
        }
        return $results;
    }

    public function browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $this->record('browse', [$nodeId, $direction]);
        $k = $this->key($nodeId);
        if (isset($this->browseHandlers[$k])) {
            return ($this->browseHandlers[$k])();
        }
        return [];
    }

    public function browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): BrowseResultSet
    {
        $this->record('browseWithContinuation', [$nodeId]);
        return new BrowseResultSet($this->browse($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses), null);
    }

    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        $this->record('browseNext', [$continuationPoint]);
        return new BrowseResultSet([], null);
    }

    public function browseAll(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $this->record('browseAll', [$nodeId]);
        return $this->browse($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses);
    }

    public function browseRecursive(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): array
    {
        $this->record('browseRecursive', [$nodeId, $maxDepth]);
        return [];
    }

    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $this->record('call', [$objectId, $methodId, $inputArguments]);
        $k = $this->key($objectId) . '|' . $this->key($methodId);
        if (isset($this->callHandlers[$k])) {
            return ($this->callHandlers[$k])($inputArguments);
        }
        return new CallResult(0, [], []);
    }

    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        $this->record('getEndpoints', [$endpointUrl]);
        return [];
    }

    public function translateBrowsePaths(?array $browsePaths = null): array|BrowsePathsBuilder
    {
        if ($browsePaths === null) {
            return new BrowsePathsBuilder($this);
        }
        $this->record('translateBrowsePaths', [$browsePaths]);
        return [];
    }

    public function resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
    {
        $this->record('resolveNodeId', [$path, $startingNodeId]);
        $normalized = trim($path, '/');
        if (isset($this->resolveHandlers[$normalized])) {
            return ($this->resolveHandlers[$normalized])();
        }
        return NodeId::numeric(0, 0);
    }

    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        $this->record('discoverDataTypes', [$namespaceIndex]);
        return 0;
    }
    public function createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0): SubscriptionResult
    {
        $this->record('createSubscription', [$publishingInterval]);
        return new SubscriptionResult(1, $publishingInterval, $lifetimeCount, $maxKeepAliveCount);
    }

    public function createMonitoredItems(int $subscriptionId, ?array $monitoredItems = null): array|MonitoredItemsBuilder
    {
        if ($monitoredItems === null) {
            return new MonitoredItemsBuilder($this, $subscriptionId);
        }
        $this->record('createMonitoredItems', [$subscriptionId, $monitoredItems]);
        return array_map(fn($i, $item) => new MonitoredItemResult(0, $i + 1, 0, 1), array_keys($monitoredItems), $monitoredItems);
    }

    public function createEventMonitoredItem(int $subscriptionId, NodeId|string $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1): MonitoredItemResult
    {
        $this->record('createEventMonitoredItem', [$subscriptionId, $nodeId]);
        return new MonitoredItemResult(0, 1, 0, 1);
    }

    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        $this->record('deleteMonitoredItems', [$subscriptionId, $monitoredItemIds]);
        return array_fill(0, count($monitoredItemIds), 0);
    }

    public function deleteSubscription(int $subscriptionId): int
    {
        $this->record('deleteSubscription', [$subscriptionId]);
        return 0;
    }

    public function publish(array $acknowledgements = []): PublishResult
    {
        $this->record('publish', [$acknowledgements]);
        return new PublishResult(1, 1, false, [], []);
    }

    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array
    {
        $this->record('transferSubscriptions', [$subscriptionIds, $sendInitialValues]);
        return array_map(fn($id) => new TransferResult(0, []), $subscriptionIds);
    }

    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        $this->record('republish', [$subscriptionId, $retransmitSequenceNumber]);
        return ['sequenceNumber' => $retransmitSequenceNumber, 'publishTime' => null, 'notifications' => []];
    }
    public function historyReadRaw(NodeId|string $nodeId, ?DateTimeImmutable $startTime = null, ?DateTimeImmutable $endTime = null, int $numValuesPerNode = 0, bool $returnBounds = false): array
    {
        $this->record('historyReadRaw', [$nodeId]);
        return [];
    }

    public function historyReadProcessed(NodeId|string $nodeId, DateTimeImmutable $startTime, DateTimeImmutable $endTime, float $processingInterval, NodeId $aggregateType): array
    {
        $this->record('historyReadProcessed', [$nodeId]);
        return [];
    }

    public function historyReadAtTime(NodeId|string $nodeId, array $timestamps): array
    {
        $this->record('historyReadAtTime', [$nodeId]);
        return [];
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
}
