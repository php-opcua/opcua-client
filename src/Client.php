<?php

declare(strict_types=1);

namespace PhpOpcua\Client;

use DateTimeImmutable;
use PhpOpcua\Client\Client\ManagesBatchingRuntimeTrait;
use PhpOpcua\Client\Client\ManagesCacheRuntimeTrait;
use PhpOpcua\Client\Client\ManagesConnectionTrait;
use PhpOpcua\Client\Client\ManagesEventDispatchTrait;
use PhpOpcua\Client\Client\ManagesHandshakeTrait;
use PhpOpcua\Client\Client\ManagesSecureChannelTrait;
use PhpOpcua\Client\Client\ManagesSessionTrait;
use PhpOpcua\Client\Client\ManagesTrustStoreRuntimeTrait;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Exception\ModuleConflictException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Kernel\ClientKernelInterface;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Transport\TcpTransport;
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
 * Connected OPC UA client providing browsing, reading, writing, subscriptions, and history access.
 *
 * Instances are created via {@see ClientBuilder::connect()}. Do not instantiate directly.
 * Service operations are provided by modules loaded via the {@see ModuleRegistry}.
 *
 * @implements OpcUaClientInterface
 * @implements ClientKernelInterface
 *
 * @see OpcUaClientInterface
 * @see ClientKernelInterface
 * @see ClientBuilder
 */
class Client implements OpcUaClientInterface, ClientKernelInterface
{
    use ManagesEventDispatchTrait;
    use ManagesCacheRuntimeTrait;
    use ManagesBatchingRuntimeTrait;
    use ManagesTrustStoreRuntimeTrait;
    use ManagesConnectionTrait;
    use ManagesHandshakeTrait;
    use ManagesSecureChannelTrait;
    use ManagesSessionTrait;

    private TcpTransport $transport;

    private ?SessionService $session = null;

    private ?NodeId $authenticationToken = null;

    private int $secureChannelId = 0;

    private int $requestId = 10;

    private SecurityPolicy $securityPolicy;

    private SecurityMode $securityMode;

    private ?string $username;

    private ?string $password;

    private ?string $clientCertPath;

    private ?string $clientKeyPath;

    private ?string $caCertPath;

    private ?string $userCertPath;

    private ?string $userKeyPath;

    private ?string $serverCertDer = null;

    private ?SecureChannel $secureChannel = null;

    private ?string $serverNonce = null;

    private ?string $eccServerEphemeralKey = null;

    private ?string $usernamePolicyId = null;

    private ?string $certificatePolicyId = null;

    private ?string $anonymousPolicyId = null;

    private ?string $lastEndpointUrl = null;

    private ConnectionState $connectionState = ConnectionState::Disconnected;

    private LoggerInterface $logger;

    private EventDispatcherInterface $eventDispatcher;

    private ?TrustStoreInterface $trustStore;

    private ?TrustPolicy $trustPolicy;

    private bool $autoAcceptEnabled;

    private bool $autoAcceptForce;

    private ?CacheInterface $cache;

    private bool $cacheInitialized;

    private float $timeout;

    private ?int $autoRetry;

    private ?int $batchSize;

    private ?int $serverMaxNodesPerRead = null;

    private ?int $serverMaxNodesPerWrite = null;

    private int $defaultBrowseMaxDepth;

    private bool $autoDetectWriteType;

    private bool $readMetadataCache;

    private ExtensionObjectRepository $extensionObjectRepository;

    /** @var array<string, class-string<\BackedEnum>> */
    private array $enumMappings;

    private ModuleRegistry $moduleRegistry;

    /** @var array<string, callable> */
    private array $methodHandlers = [];

    /** @var array<string, string> */
    private array $methodOwners = [];

    private string $currentModuleClass = '';

    /**
     * Create a connected OPC UA client. Called internally by {@see ClientBuilder::connect()}.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @param SecurityPolicy $securityPolicy The security policy.
     * @param SecurityMode $securityMode The security mode.
     * @param ?string $clientCertPath Client certificate path.
     * @param ?string $clientKeyPath Client private key path.
     * @param ?string $caCertPath CA certificate path.
     * @param ?string $username Username for authentication.
     * @param ?string $password Password for authentication.
     * @param ?string $userCertPath User certificate path.
     * @param ?string $userKeyPath User private key path.
     * @param LoggerInterface $logger PSR-3 logger.
     * @param EventDispatcherInterface $eventDispatcher PSR-14 event dispatcher.
     * @param ?TrustStoreInterface $trustStore Trust store.
     * @param ?TrustPolicy $trustPolicy Trust policy.
     * @param bool $autoAcceptEnabled Auto-accept TOFU.
     * @param bool $autoAcceptForce Force auto-accept.
     * @param ?CacheInterface $cache PSR-16 cache.
     * @param bool $cacheInitialized Whether cache is initialized.
     * @param float $timeout Network timeout in seconds.
     * @param ?int $autoRetry Max retry count.
     * @param ?int $batchSize Batch size for multi operations.
     * @param int $defaultBrowseMaxDepth Default browse max depth.
     * @param bool $autoDetectWriteType Enable write type auto-detection.
     * @param bool $readMetadataCache Enable metadata read caching.
     * @param ExtensionObjectRepository $extensionObjectRepository Codec registry.
     * @param array<string, class-string<\BackedEnum>> $enumMappings Enum mappings.
     * @param ModuleRegistry $moduleRegistry Module registry.
     *
     * @throws Exception\ConfigurationException If the endpoint URL is invalid.
     * @throws Exception\ConnectionException If the TCP connection or handshake fails.
     * @throws ServiceException If a protocol-level error occurs.
     */
    public function __construct(
        string $endpointUrl,
        SecurityPolicy $securityPolicy,
        SecurityMode $securityMode,
        ?string $clientCertPath,
        ?string $clientKeyPath,
        ?string $caCertPath,
        ?string $username,
        ?string $password,
        ?string $userCertPath,
        ?string $userKeyPath,
        LoggerInterface $logger,
        EventDispatcherInterface $eventDispatcher,
        ?TrustStoreInterface $trustStore,
        ?TrustPolicy $trustPolicy,
        bool $autoAcceptEnabled,
        bool $autoAcceptForce,
        ?CacheInterface $cache,
        bool $cacheInitialized,
        float $timeout,
        ?int $autoRetry,
        ?int $batchSize,
        int $defaultBrowseMaxDepth,
        bool $autoDetectWriteType,
        bool $readMetadataCache,
        ExtensionObjectRepository $extensionObjectRepository,
        array $enumMappings,
        ?ModuleRegistry $moduleRegistry = null,
    ) {
        $this->securityPolicy = $securityPolicy;
        $this->securityMode = $securityMode;
        $this->clientCertPath = $clientCertPath;
        $this->clientKeyPath = $clientKeyPath;
        $this->caCertPath = $caCertPath;
        $this->username = $username;
        $this->password = $password;
        $this->userCertPath = $userCertPath;
        $this->userKeyPath = $userKeyPath;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->trustStore = $trustStore;
        $this->trustPolicy = $trustPolicy;
        $this->autoAcceptEnabled = $autoAcceptEnabled;
        $this->autoAcceptForce = $autoAcceptForce;
        $this->cache = $cache;
        $this->cacheInitialized = $cacheInitialized;
        $this->timeout = $timeout;
        $this->autoRetry = $autoRetry;
        $this->batchSize = $batchSize;
        $this->defaultBrowseMaxDepth = $defaultBrowseMaxDepth;
        $this->autoDetectWriteType = $autoDetectWriteType;
        $this->readMetadataCache = $readMetadataCache;
        $this->extensionObjectRepository = $extensionObjectRepository;
        $this->enumMappings = $enumMappings;
        $this->moduleRegistry = $moduleRegistry ?? new ModuleRegistry();
        $this->transport = new TcpTransport();

        $this->performConnect($endpointUrl);
    }

    /**
     * Register a method handler provided by a module. Re-registration by the
     * same owner module class is allowed so that handlers survive a
     * disconnect/reconnect cycle without a spurious {@see ModuleConflictException}.
     *
     * @param string $name The method name.
     * @param callable $handler The handler callable.
     * @return void
     *
     * @throws ModuleConflictException If the method is already registered by another module.
     */
    public function registerMethod(string $name, callable $handler): void
    {
        if (isset($this->methodHandlers[$name])
            && $this->methodOwners[$name] !== $this->currentModuleClass
        ) {
            throw new ModuleConflictException(
                "Method '{$name}' is already registered by {$this->methodOwners[$name]}",
            );
        }
        $this->methodHandlers[$name] = $handler;
        $this->methodOwners[$name] = $this->currentModuleClass;
    }

    /**
     * Set the current module class being registered (called by ModuleRegistry during boot).
     *
     * @param string $class The fully-qualified module class name.
     * @return void
     */
    public function setCurrentModuleClass(string $class): void
    {
        $this->currentModuleClass = $class;
    }

    /**
     * Check whether a method is registered by any loaded module.
     *
     * @param string $name The method name.
     * @return bool
     */
    public function hasMethod(string $name): bool
    {
        return isset($this->methodHandlers[$name]);
    }

    /**
     * @return string[]
     */
    public function getRegisteredMethods(): array
    {
        return array_keys($this->methodHandlers);
    }

    /**
     * @return class-string[]
     */
    public function getLoadedModules(): array
    {
        return $this->moduleRegistry->getModuleClasses();
    }

    /**
     * Check whether a module class is loaded.
     *
     * @param string $moduleClass The fully-qualified module class name.
     * @return bool
     */
    public function hasModule(string $moduleClass): bool
    {
        return $this->moduleRegistry->has($moduleClass);
    }

    /**
     * Dynamically dispatch a method call to the registered module handler.
     *
     * @param string $method The method name.
     * @param array<int, mixed> $args The method arguments.
     * @return mixed
     *
     * @throws \BadMethodCallException If the method is not registered by any module.
     */
    public function __call(string $method, array $args): mixed
    {
        if (isset($this->methodHandlers[$method])) {
            return ($this->methodHandlers[$method])(...$args);
        }
        throw new \BadMethodCallException("Method '{$method}' is not registered. Is the module loaded?");
    }

    /**
     * Send raw data over the transport.
     *
     * @param string $data The raw bytes to send.
     * @return void
     */
    public function send(string $data): void
    {
        $this->transport->send($data);
    }

    /**
     * Receive raw data from the transport.
     *
     * @return string
     */
    public function receive(): string
    {
        return $this->transport->receive();
    }

    /**
     * Generate and return the next sequential request ID.
     *
     * @return int
     */
    public function nextRequestId(): int
    {
        return $this->requestId++;
    }

    /**
     * Get the current authentication token.
     *
     * @return NodeId
     */
    public function getAuthToken(): NodeId
    {
        return $this->authenticationToken;
    }

    /**
     * Unwrap a raw transport response, handling ERR messages and secure channel decryption.
     *
     * @param string $response The raw response bytes from the transport layer.
     * @return string The decoded response body.
     *
     * @throws ServiceException If the server returned an ERR message.
     */
    public function unwrapResponse(string $response): string
    {
        if (str_starts_with($response, 'ERR')) {
            $decoder = $this->createDecoder($response);
            MessageHeader::decode($decoder);
            $errorCode = $decoder->readUInt32();
            $reason = $decoder->readString() ?? 'Unknown error';
            throw new ServiceException(sprintf('Server error 0x%08X: %s', $errorCode, $reason), $errorCode);
        }

        if ($this->secureChannel !== null && $this->secureChannel->isSecurityActive()) {
            return $this->secureChannel->processMessage($response);
        }

        return substr($response, MessageHeader::HEADER_SIZE + 4);
    }

    /**
     * Create a BinaryDecoder for the given data buffer.
     *
     * @param string $data The binary data to decode.
     * @return BinaryDecoder
     */
    public function createDecoder(string $data): BinaryDecoder
    {
        return new BinaryDecoder($data, $this->extensionObjectRepository);
    }

    /**
     * Resolve a NodeId parameter that may be passed as a string.
     *
     * When called with a single argument (kernel usage), parses a NodeId string
     * into a NodeId object. When called with additional arguments (client interface
     * usage), delegates to the module-provided browse path resolver.
     *
     * @param NodeId|string $nodeId The node identifier or browse path string.
     * @param NodeId|string|null $startingNodeId Starting node for browse path resolution.
     * @param bool $useCache Whether to use the browse cache for path resolution.
     * @return NodeId
     *
     * @throws Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     */
    public function resolveNodeId(NodeId|string $nodeId, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
    {
        if ($nodeId instanceof NodeId) {
            return $nodeId;
        }

        if ($startingNodeId !== null) {
            return ($this->methodHandlers['resolveNodeId'])($nodeId, $startingNodeId, $useCache);
        }

        if (preg_match('/^(ns=\d+;)?[isgb]=/', $nodeId) === 1) {
            return NodeId::parse($nodeId);
        }

        if (isset($this->methodHandlers['resolveNodeId']) && str_contains($nodeId, '/')) {
            return ($this->methodHandlers['resolveNodeId'])($nodeId, $startingNodeId, $useCache);
        }

        return NodeId::parse($nodeId);
    }

    /**
     * Resolve string NodeIds in an array of items to NodeId objects.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string $key
     * @return void
     */
    public function resolveNodeIdArray(array &$items, string $key = 'nodeId'): void
    {
        foreach ($items as &$item) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $item[$key] = NodeId::parse($item[$key]);
            }
        }
        unset($item);
    }

    /**
     * Return the logger instance.
     *
     * @return LoggerInterface
     */
    public function log(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Build a log context array with endpoint and session_id prepended.
     *
     * @param array<string, mixed> $context Additional context data.
     * @return array<string, mixed>
     */
    public function logContext(array $context = []): array
    {
        return array_merge([
            'endpoint' => $this->lastEndpointUrl,
            'session_id' => $this->authenticationToken !== null ? (string) $this->authenticationToken : null,
        ], $context);
    }

    /**
     * Get the configured logger.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get the current PSR-14 event dispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Return the extension object repository used for custom type decoding.
     *
     * @return ExtensionObjectRepository
     *
     * @see ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->extensionObjectRepository;
    }

    /**
     * Get the current network timeout.
     *
     * @return float Timeout in seconds.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Get the current automatic retry count.
     *
     * @return int
     */
    public function getAutoRetry(): int
    {
        if ($this->autoRetry !== null) {
            return $this->autoRetry;
        }

        return $this->lastEndpointUrl !== null ? 1 : 0;
    }

    /**
     * Get the default maximum depth for recursive browse operations.
     *
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int
    {
        return $this->defaultBrowseMaxDepth;
    }

    /**
     * Whether automatic write type detection is enabled.
     *
     * @return bool
     */
    public function isAutoDetectWriteType(): bool
    {
        return $this->autoDetectWriteType;
    }

    /**
     * Whether metadata read caching is enabled.
     *
     * @return bool
     */
    public function isReadMetadataCache(): bool
    {
        return $this->readMetadataCache;
    }

    /**
     * Get the registered enum mappings.
     *
     * @return array<string, class-string<\BackedEnum>>
     */
    public function getEnumMappings(): array
    {
        return $this->enumMappings;
    }

    /**
     * @param ?int $namespaceIndex
     * @param bool $useCache
     * @return int
     */
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        return ($this->methodHandlers['discoverDataTypes'])($namespaceIndex, $useCache);
    }

    /**
     * @return ?string
     */
    public function getServerProductName(): ?string
    {
        return ($this->methodHandlers['getServerProductName'])();
    }

    /**
     * @return ?string
     */
    public function getServerManufacturerName(): ?string
    {
        return ($this->methodHandlers['getServerManufacturerName'])();
    }

    /**
     * @return ?string
     */
    public function getServerSoftwareVersion(): ?string
    {
        return ($this->methodHandlers['getServerSoftwareVersion'])();
    }

    /**
     * @return ?string
     */
    public function getServerBuildNumber(): ?string
    {
        return ($this->methodHandlers['getServerBuildNumber'])();
    }

    /**
     * @return ?DateTimeImmutable
     */
    public function getServerBuildDate(): ?DateTimeImmutable
    {
        return ($this->methodHandlers['getServerBuildDate'])();
    }

    /**
     * @return BuildInfo
     */
    public function getServerBuildInfo(): BuildInfo
    {
        return ($this->methodHandlers['getServerBuildInfo'])();
    }

    /**
     * @param string $endpointUrl
     * @param bool $useCache
     * @return EndpointDescription[]
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        return ($this->methodHandlers['getEndpoints'])($endpointUrl, $useCache);
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @param bool $useCache
     * @return ReferenceDescription[]
     */
    public function browse(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
        bool $useCache = true,
    ): array {
        return ($this->methodHandlers['browse'])($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses, $useCache);
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @return BrowseResultSet
     */
    public function browseWithContinuation(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
    ): BrowseResultSet {
        return ($this->methodHandlers['browseWithContinuation'])($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses);
    }

    /**
     * @param string $continuationPoint
     * @return BrowseResultSet
     */
    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        return ($this->methodHandlers['browseNext'])($continuationPoint);
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @param bool $useCache
     * @return ReferenceDescription[]
     */
    public function browseAll(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
        bool $useCache = true,
    ): array {
        return ($this->methodHandlers['browseAll'])($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses, $useCache);
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?int $maxDepth
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @return BrowseNode[]
     */
    public function browseRecursive(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int $maxDepth = null,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
    ): array {
        return ($this->methodHandlers['browseRecursive'])($nodeId, $direction, $maxDepth, $referenceTypeId, $includeSubtypes, $nodeClasses);
    }

    /**
     * @param array<array{startingNodeId: NodeId|string, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}>|null $browsePaths
     * @return BrowsePathResult[]|Builder\BrowsePathsBuilder
     */
    public function translateBrowsePaths(?array $browsePaths = null): array|Builder\BrowsePathsBuilder
    {
        return ($this->methodHandlers['translateBrowsePaths'])($browsePaths);
    }

    /**
     * @param NodeId|string $nodeId
     * @param int $attributeId
     * @param bool $refresh
     * @return DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
    {
        return ($this->methodHandlers['read'])($nodeId, $attributeId, $refresh);
    }

    /**
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int}> $readItems
     * @return DataValue[]|Builder\ReadMultiBuilder
     */
    public function readMulti(?array $readItems = null): array|Builder\ReadMultiBuilder
    {
        return ($this->methodHandlers['readMulti'])($readItems);
    }

    /**
     * @param NodeId|string $nodeId
     * @param mixed $value
     * @param ?BuiltinType $type
     * @return int
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
    {
        return ($this->methodHandlers['write'])($nodeId, $value, $type);
    }

    /**
     * @param ?array<array{nodeId: NodeId|string, value: mixed, type?: ?BuiltinType, attributeId?: int}> $writeItems
     * @return int[]|Builder\WriteMultiBuilder
     */
    public function writeMulti(?array $writeItems = null): array|Builder\WriteMultiBuilder
    {
        return ($this->methodHandlers['writeMulti'])($writeItems);
    }

    /**
     * @param NodeId|string $objectId
     * @param NodeId|string $methodId
     * @param Variant[] $inputArguments
     * @return CallResult
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        return ($this->methodHandlers['call'])($objectId, $methodId, $inputArguments);
    }

    /**
     * @param float $publishingInterval
     * @param int $lifetimeCount
     * @param int $maxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     * @return SubscriptionResult
     */
    public function createSubscription(
        float $publishingInterval = 500.0,
        int $lifetimeCount = 2400,
        int $maxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        bool $publishingEnabled = true,
        int $priority = 0,
    ): SubscriptionResult {
        return ($this->methodHandlers['createSubscription'])($publishingInterval, $lifetimeCount, $maxKeepAliveCount, $maxNotificationsPerPublish, $publishingEnabled, $priority);
    }

    /**
     * @param int $subscriptionId
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @return MonitoredItemResult[]|Builder\MonitoredItemsBuilder
     */
    public function createMonitoredItems(
        int $subscriptionId,
        ?array $items = null,
    ): array|Builder\MonitoredItemsBuilder {
        return ($this->methodHandlers['createMonitoredItems'])($subscriptionId, $items);
    }

    /**
     * @param int $subscriptionId
     * @param NodeId|string $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     * @return MonitoredItemResult
     */
    public function createEventMonitoredItem(
        int $subscriptionId,
        NodeId|string $nodeId,
        array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int $clientHandle = 1,
    ): MonitoredItemResult {
        return ($this->methodHandlers['createEventMonitoredItem'])($subscriptionId, $nodeId, $selectFields, $clientHandle);
    }

    /**
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     * @return int[]
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        return ($this->methodHandlers['deleteMonitoredItems'])($subscriptionId, $monitoredItemIds);
    }

    /**
     * @param int $subscriptionId
     * @param array<array{monitoredItemId: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, discardOldest?: bool}> $itemsToModify
     * @return Module\Subscription\MonitoredItemModifyResult[]
     */
    public function modifyMonitoredItems(int $subscriptionId, array $itemsToModify): array
    {
        return ($this->methodHandlers['modifyMonitoredItems'])($subscriptionId, $itemsToModify);
    }

    /**
     * @param int $subscriptionId
     * @param int $triggeringItemId
     * @param int[] $linksToAdd
     * @param int[] $linksToRemove
     * @return Module\Subscription\SetTriggeringResult
     */
    public function setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = []): Module\Subscription\SetTriggeringResult
    {
        return ($this->methodHandlers['setTriggering'])($subscriptionId, $triggeringItemId, $linksToAdd, $linksToRemove);
    }

    /**
     * @param int $subscriptionId
     * @return int
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        return ($this->methodHandlers['deleteSubscription'])($subscriptionId);
    }

    /**
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     * @return PublishResult
     */
    public function publish(array $acknowledgements = []): PublishResult
    {
        return ($this->methodHandlers['publish'])($acknowledgements);
    }

    /**
     * @param int[] $subscriptionIds
     * @param bool $sendInitialValues
     * @return Module\Subscription\TransferResult[]
     */
    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array
    {
        return ($this->methodHandlers['transferSubscriptions'])($subscriptionIds, $sendInitialValues);
    }

    /**
     * @param int $subscriptionId
     * @param int $retransmitSequenceNumber
     * @return array{sequenceNumber: int, publishTime: ?DateTimeImmutable, notifications: array}
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        return ($this->methodHandlers['republish'])($subscriptionId, $retransmitSequenceNumber);
    }

    /**
     * @param NodeId|string $nodeId
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     * @return DataValue[]
     */
    public function historyReadRaw(
        NodeId|string $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
    ): array {
        return ($this->methodHandlers['historyReadRaw'])($nodeId, $startTime, $endTime, $numValuesPerNode, $returnBounds);
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     * @return DataValue[]
     */
    public function historyReadProcessed(
        NodeId|string $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array {
        return ($this->methodHandlers['historyReadProcessed'])($nodeId, $startTime, $endTime, $processingInterval, $aggregateType);
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable[] $timestamps
     * @return DataValue[]
     */
    public function historyReadAtTime(
        NodeId|string $nodeId,
        array $timestamps,
    ): array {
        return ($this->methodHandlers['historyReadAtTime'])($nodeId, $timestamps);
    }

    /**
     * @param array<array{parentNodeId: NodeId|string, referenceTypeId: NodeId|string, requestedNewNodeId: NodeId|string, browseName: QualifiedName, nodeClass: NodeClass, typeDefinition: NodeId|string, displayName?: ?string, description?: ?string, writeMask?: int, userWriteMask?: int}> $nodesToAdd
     * @return AddNodesResult[]
     */
    public function addNodes(array $nodesToAdd): array
    {
        return ($this->methodHandlers['addNodes'])($nodesToAdd);
    }

    /**
     * @param array<array{nodeId: NodeId|string, deleteTargetReferences?: bool}> $nodesToDelete
     * @return int[]
     */
    public function deleteNodes(array $nodesToDelete): array
    {
        return ($this->methodHandlers['deleteNodes'])($nodesToDelete);
    }

    /**
     * @param array<array{sourceNodeId: NodeId|string, referenceTypeId: NodeId|string, isForward: bool, targetNodeId: NodeId|string, targetNodeClass: NodeClass, targetServerUri?: ?string}> $referencesToAdd
     * @return int[]
     */
    public function addReferences(array $referencesToAdd): array
    {
        return ($this->methodHandlers['addReferences'])($referencesToAdd);
    }

    /**
     * @param array<array{sourceNodeId: NodeId|string, referenceTypeId: NodeId|string, isForward: bool, targetNodeId: NodeId|string, deleteBidirectional?: bool}> $referencesToDelete
     * @return int[]
     */
    public function deleteReferences(array $referencesToDelete): array
    {
        return ($this->methodHandlers['deleteReferences'])($referencesToDelete);
    }
}
