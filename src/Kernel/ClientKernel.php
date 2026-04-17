<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Kernel;

use Closure;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Event\CacheHit;
use PhpOpcua\Client\Event\CacheMiss;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\InvalidNodeIdException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Protocol\MessageHeader;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\SecureChannel;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Shared infrastructure for all service modules.
 *
 * Provides the low-level operations that every module needs: transport I/O,
 * request/response handling, retry logic, NodeId resolution, caching, logging,
 * and event dispatching.
 *
 * Modules receive a ClientKernel instance via {@see \PhpOpcua\Client\Module\ServiceModule::setKernel()}.
 * They must not access transport, session, or security internals directly.
 */
class ClientKernel implements ClientKernelInterface
{
    private TcpTransport $transport;

    private ?NodeId $authenticationToken = null;

    private int $secureChannelId = 0;

    private ?SecureChannel $secureChannel = null;

    private int $requestId = 10;

    private ?string $lastEndpointUrl = null;

    private ConnectionState $connectionState = ConnectionState::Disconnected;

    private ?CacheInterface $cache;

    private bool $cacheInitialized;

    private ?int $serverMaxNodesPerRead = null;

    private ?int $serverMaxNodesPerWrite = null;

    private ExtensionObjectRepository $extensionObjectRepository;

    private const CACHE_SAFE_PREFIX = "\x00opcua\x00";

    /**
     * @param LoggerInterface $logger
     * @param EventDispatcherInterface $eventDispatcher
     * @param ?CacheInterface $cache
     * @param bool $cacheInitialized
     * @param float $timeout
     * @param ?int $autoRetry
     * @param ?int $batchSize
     * @param int $defaultBrowseMaxDepth
     * @param bool $autoDetectWriteType
     * @param bool $readMetadataCache
     * @param ExtensionObjectRepository $extensionObjectRepository
     * @param array<string, class-string<\BackedEnum>> $enumMappings
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?CacheInterface $cache,
        bool $cacheInitialized,
        private readonly float $timeout,
        private readonly ?int $autoRetry,
        private readonly ?int $batchSize,
        private readonly int $defaultBrowseMaxDepth,
        private readonly bool $autoDetectWriteType,
        private readonly bool $readMetadataCache,
        ExtensionObjectRepository $extensionObjectRepository,
        private readonly array $enumMappings,
    ) {
        $this->cache = $cache;
        $this->cacheInitialized = $cacheInitialized;
        $this->extensionObjectRepository = $extensionObjectRepository;
        $this->transport = new TcpTransport();
    }

    /**
     * @param Closure(): T $operation
     * @return T
     *
     * @template T
     *
     * @throws ConnectionException
     */
    public function executeWithRetry(Closure $operation): mixed
    {
        $maxRetries = $this->getAutoRetry();

        for ($attempt = 0; ; $attempt++) {
            try {
                return $operation();
            } catch (ConnectionException $e) {
                $this->connectionState = ConnectionState::Broken;

                if ($attempt >= $maxRetries || $this->lastEndpointUrl === null) {
                    throw $e;
                }

                $this->logger->warning('Connection lost, retrying ({attempt}/{max})', $this->logContext([
                    'attempt' => $attempt + 1,
                    'max' => $maxRetries,
                ]));

                throw $e;
            }
        }
    }

    /**
     * @throws ConnectionException
     */
    public function ensureConnected(): void
    {
        if ($this->connectionState === ConnectionState::Connected) {
            return;
        }

        throw match ($this->connectionState) {
            ConnectionState::Disconnected => new ConnectionException('Not connected: call connect() first'),
            ConnectionState::Broken => new ConnectionException('Connection lost: call reconnect() or connect() to re-establish'),
            default => new ConnectionException('No explicit exception for state: ' . $this->connectionState->name),
        };
    }

    /**
     * @param string $data
     */
    public function send(string $data): void
    {
        $this->transport->send($data);
    }

    /**
     * @return string
     */
    public function receive(): string
    {
        return $this->transport->receive();
    }

    /**
     * @return int
     */
    public function nextRequestId(): int
    {
        return $this->requestId++;
    }

    /**
     * @return NodeId
     */
    public function getAuthToken(): NodeId
    {
        return $this->authenticationToken ?? NodeId::numeric(0, 0);
    }

    /**
     * @param string $response
     * @return string
     *
     * @throws ServiceException
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
     * @param string $data
     * @return BinaryDecoder
     */
    public function createDecoder(string $data): BinaryDecoder
    {
        return new BinaryDecoder($data, $this->extensionObjectRepository);
    }

    /**
     * @param NodeId|string $nodeId
     * @return NodeId
     *
     * @throws InvalidNodeIdException
     */
    public function resolveNodeId(NodeId|string $nodeId): NodeId
    {
        return is_string($nodeId) ? NodeId::parse($nodeId) : $nodeId;
    }

    /**
     * @param array $items
     * @param string $key
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
     * @return LoggerInterface
     */
    public function log(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param array<string, mixed> $context
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
     * @param object $event Event object or Closure that creates one.
     */
    public function dispatch(object $event): void
    {
        if ($this->eventDispatcher instanceof NullEventDispatcher) {
            return;
        }

        $this->eventDispatcher->dispatch($event instanceof Closure ? $event() : $event);
    }

    /**
     * @param string $key
     * @param callable $fetcher
     * @param bool $useCache
     * @return mixed
     */
    public function cachedFetch(string $key, callable $fetcher, bool $useCache): mixed
    {
        $this->ensureCacheInitialized();

        if ($useCache && $this->cache !== null) {
            $cached = $this->unwrapCacheValue($this->cache->get($key));
            if ($cached !== null) {
                $this->dispatch(fn () => new CacheHit(null, $key));

                return $cached;
            }
            $this->dispatch(fn () => new CacheMiss(null, $key));
        }

        $result = $fetcher();

        if ($useCache && $this->cache !== null) {
            $this->cache->set($key, $this->wrapCacheValue($result));
        }

        return $result;
    }

    /**
     * @param string $type
     * @param NodeId $nodeId
     * @param string $paramsSuffix
     * @return string
     */
    public function buildCacheKey(string $type, NodeId $nodeId, string $paramsSuffix = ''): string
    {
        $endpointHash = md5($this->lastEndpointUrl ?? 'unknown');
        $key = sprintf('opcua:%s:%s:%s', $endpointHash, $type, $nodeId->__toString());
        if ($paramsSuffix !== '') {
            $key .= ':' . $paramsSuffix;
        }

        return $key;
    }

    /**
     * @param string $type
     * @param string $paramsSuffix
     * @return string
     */
    public function buildSimpleCacheKey(string $type, string $paramsSuffix = ''): string
    {
        $endpointHash = md5($this->lastEndpointUrl ?? 'unknown');
        $key = sprintf('opcua:%s:%s', $endpointHash, $type);
        if ($paramsSuffix !== '') {
            $key .= ':' . $paramsSuffix;
        }

        return $key;
    }

    /**
     * @return void
     */
    public function ensureCacheInitialized(): void
    {
        if (! $this->cacheInitialized) {
            $this->cache = new InMemoryCache(300);
            $this->cacheInitialized = true;
        }
    }

    /**
     * @return ?CacheInterface
     */
    public function getCache(): ?CacheInterface
    {
        $this->ensureCacheInitialized();

        return $this->cache;
    }

    /**
     * @return ?int
     */
    public function getEffectiveReadBatchSize(): ?int
    {
        if ($this->batchSize !== null) {
            return $this->batchSize > 0 ? $this->batchSize : null;
        }

        return $this->serverMaxNodesPerRead;
    }

    /**
     * @return ?int
     */
    public function getEffectiveWriteBatchSize(): ?int
    {
        if ($this->batchSize !== null) {
            return $this->batchSize > 0 ? $this->batchSize : null;
        }

        return $this->serverMaxNodesPerWrite;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
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
     * @return ?int
     */
    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }

    /**
     * @return ?int
     */
    public function getServerMaxNodesPerRead(): ?int
    {
        return $this->serverMaxNodesPerRead;
    }

    /**
     * @return ?int
     */
    public function getServerMaxNodesPerWrite(): ?int
    {
        return $this->serverMaxNodesPerWrite;
    }

    /**
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int
    {
        return $this->defaultBrowseMaxDepth;
    }

    /**
     * @return bool
     */
    public function isAutoDetectWriteType(): bool
    {
        return $this->autoDetectWriteType;
    }

    /**
     * @return bool
     */
    public function isReadMetadataCache(): bool
    {
        return $this->readMetadataCache;
    }

    /**
     * @return ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->extensionObjectRepository;
    }

    /**
     * @return array<string, class-string<\BackedEnum>>
     */
    public function getEnumMappings(): array
    {
        return $this->enumMappings;
    }

    /**
     * @return ConnectionState
     */
    public function getConnectionState(): ConnectionState
    {
        return $this->connectionState;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connectionState === ConnectionState::Connected;
    }

    /**
     * @param NodeId $authToken
     */
    public function setAuthToken(NodeId $authToken): void
    {
        $this->authenticationToken = $authToken;
    }

    /**
     * @param int $channelId
     */
    public function setSecureChannelId(int $channelId): void
    {
        $this->secureChannelId = $channelId;
    }

    /**
     * @param ?SecureChannel $channel
     */
    public function setSecureChannel(?SecureChannel $channel): void
    {
        $this->secureChannel = $channel;
    }

    /**
     * @param ConnectionState $state
     */
    public function setConnectionState(ConnectionState $state): void
    {
        $this->connectionState = $state;
    }

    /**
     * @param ?string $url
     */
    public function setLastEndpointUrl(?string $url): void
    {
        $this->lastEndpointUrl = $url;
    }

    /**
     * @return ?string
     */
    public function getLastEndpointUrl(): ?string
    {
        return $this->lastEndpointUrl;
    }

    /**
     * @param ?int $max
     */
    public function setServerMaxNodesPerRead(?int $max): void
    {
        $this->serverMaxNodesPerRead = $max;
    }

    /**
     * @param ?int $max
     */
    public function setServerMaxNodesPerWrite(?int $max): void
    {
        $this->serverMaxNodesPerWrite = $max;
    }

    /**
     * @return TcpTransport
     */
    public function getTransport(): TcpTransport
    {
        return $this->transport;
    }

    /**
     * @return int
     */
    public function getSecureChannelId(): int
    {
        return $this->secureChannelId;
    }

    /**
     * @return ?SecureChannel
     */
    public function getSecureChannel(): ?SecureChannel
    {
        return $this->secureChannel;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function wrapCacheValue(mixed $value): string
    {
        return self::CACHE_SAFE_PREFIX . base64_encode(serialize($value));
    }

    /**
     * @param mixed $raw
     * @return mixed
     */
    private function unwrapCacheValue(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (is_string($raw) && str_starts_with($raw, self::CACHE_SAFE_PREFIX)) {
            $decoded = base64_decode(substr($raw, strlen(self::CACHE_SAFE_PREFIX)), true);
            if ($decoded === false) {
                return null;
            }
            $result = @unserialize($decoded);

            return $result !== false ? $result : null;
        }

        return $raw;
    }
}
