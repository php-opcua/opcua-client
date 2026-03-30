<?php

declare(strict_types=1);

namespace PhpOpcua\Client;

use PhpOpcua\Client\Client\ManagesBatchingRuntimeTrait;
use PhpOpcua\Client\Client\ManagesBrowseTrait;
use PhpOpcua\Client\Client\ManagesCacheRuntimeTrait;
use PhpOpcua\Client\Client\ManagesConnectionTrait;
use PhpOpcua\Client\Client\ManagesEventDispatchTrait;
use PhpOpcua\Client\Client\ManagesHandshakeTrait;
use PhpOpcua\Client\Client\ManagesHistoryTrait;
use PhpOpcua\Client\Client\ManagesReadWriteTrait;
use PhpOpcua\Client\Client\ManagesSecureChannelTrait;
use PhpOpcua\Client\Client\ManagesSessionTrait;
use PhpOpcua\Client\Client\ManagesSubscriptionsTrait;
use PhpOpcua\Client\Client\ManagesTranslateBrowsePathTrait;
use PhpOpcua\Client\Client\ManagesTrustStoreRuntimeTrait;
use PhpOpcua\Client\Client\ManagesTypeDiscoveryTrait;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Protocol\BrowseService;
use PhpOpcua\Client\Protocol\CallService;
use PhpOpcua\Client\Protocol\GetEndpointsService;
use PhpOpcua\Client\Protocol\HistoryReadService;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Protocol\MonitoredItemService;
use PhpOpcua\Client\Protocol\PublishService;
use PhpOpcua\Client\Protocol\ReadService;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Protocol\SubscriptionService;
use PhpOpcua\Client\Protocol\TranslateBrowsePathService;
use PhpOpcua\Client\Protocol\WriteService;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Connected OPC UA client providing browsing, reading, writing, subscriptions, and history access.
 *
 * Instances are created via {@see ClientBuilder::connect()}. Do not instantiate directly.
 *
 * @implements OpcUaClientInterface
 *
 * @see OpcUaClientInterface
 * @see ClientBuilder
 */
class Client implements OpcUaClientInterface
{
    use ManagesEventDispatchTrait;
    use ManagesCacheRuntimeTrait;
    use ManagesBatchingRuntimeTrait;
    use ManagesTrustStoreRuntimeTrait;
    use ManagesConnectionTrait;
    use ManagesHandshakeTrait;
    use ManagesSecureChannelTrait;
    use ManagesSessionTrait;
    use ManagesBrowseTrait;
    use ManagesReadWriteTrait;
    use ManagesSubscriptionsTrait;
    use ManagesHistoryTrait;
    use ManagesTypeDiscoveryTrait;
    use ManagesTranslateBrowsePathTrait;

    private TcpTransport $transport;

    private ?SessionService $session = null;

    private ?BrowseService $browseService = null;

    private ?ReadService $readService = null;

    private ?WriteService $writeService = null;

    private ?CallService $callService = null;

    private ?GetEndpointsService $getEndpointsService = null;

    private ?SubscriptionService $subscriptionService = null;

    private ?MonitoredItemService $monitoredItemService = null;

    private ?PublishService $publishService = null;

    private ?HistoryReadService $historyReadService = null;

    private ?TranslateBrowsePathService $translateBrowsePathService = null;

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
        $this->transport = new TcpTransport();

        $this->performConnect($endpointUrl);
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
     * Unwrap a raw transport response, handling ERR messages and secure channel decryption.
     *
     * @param string $response The raw response bytes from the transport layer.
     * @return string The decoded response body.
     *
     * @throws ServiceException If the server returned an ERR message.
     */
    private function unwrapResponse(string $response): string
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
    private function createDecoder(string $data): BinaryDecoder
    {
        return new BinaryDecoder($data, $this->extensionObjectRepository);
    }

    /**
     * Build a log context array with endpoint and session_id prepended.
     *
     * @param array<string, mixed> $context Additional context data.
     * @return array<string, mixed>
     */
    private function logContext(array $context = []): array
    {
        return array_merge([
            'endpoint' => $this->lastEndpointUrl,
            'session_id' => $this->authenticationToken !== null ? (string) $this->authenticationToken : null,
        ], $context);
    }

    /**
     * Generate and return the next sequential request ID.
     *
     * @return int
     */
    private function nextRequestId(): int
    {
        return $this->requestId++;
    }

    /**
     * Resolve a NodeId parameter that may be passed as a string.
     *
     * @param NodeId|string $nodeId The node identifier as a NodeId object or OPC UA string format.
     * @return NodeId
     *
     * @throws Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     */
    private function resolveNodeIdParam(NodeId|string $nodeId): NodeId
    {
        return is_string($nodeId) ? NodeId::parse($nodeId) : $nodeId;
    }

    /**
     * Resolve string NodeIds in an array of items to NodeId objects.
     *
     * @param array $items
     * @param string $key
     * @return void
     */
    private function resolveNodeIdArrayParam(array &$items, string $key = 'nodeId'): void
    {
        foreach ($items as &$item) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $item[$key] = NodeId::parse($item[$key]);
            }
        }
        unset($item);
    }
}
