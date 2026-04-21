<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Kernel;

use Closure;
use PhpOpcua\Client\Cache\CacheCodecInterface;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Types\NodeId;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Infrastructure API available to all service modules.
 *
 * Modules access the kernel via `$this->kernel` which is typed to this interface,
 * limiting them to infrastructure operations only (no service methods).
 */
interface ClientKernelInterface
{
    /** @template T @param Closure(): T $operation @return T */
    public function executeWithRetry(Closure $operation): mixed;

    public function ensureConnected(): void;

    public function send(string $data): void;

    public function receive(): string;

    public function nextRequestId(): int;

    public function getAuthToken(): NodeId;

    public function unwrapResponse(string $response): string;

    public function createDecoder(string $data): BinaryDecoder;

    public function resolveNodeId(NodeId|string $nodeId): NodeId;

    public function resolveNodeIdArray(array &$items, string $key = 'nodeId'): void;

    public function log(): LoggerInterface;

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function logContext(array $context = []): array;

    public function dispatch(object $event): void;

    public function cachedFetch(string $key, callable $fetcher, bool $useCache): mixed;

    public function buildCacheKey(string $type, NodeId $nodeId, string $paramsSuffix = ''): string;

    public function buildSimpleCacheKey(string $type, string $paramsSuffix = ''): string;

    public function ensureCacheInitialized(): void;

    public function getCache(): ?CacheInterface;

    public function getCacheCodec(): CacheCodecInterface;

    public function getEffectiveReadBatchSize(): ?int;

    public function getEffectiveWriteBatchSize(): ?int;

    public function getLogger(): LoggerInterface;

    public function getEventDispatcher(): EventDispatcherInterface;

    public function getTimeout(): float;

    public function getAutoRetry(): int;

    public function getBatchSize(): ?int;

    public function getServerMaxNodesPerRead(): ?int;

    public function getServerMaxNodesPerWrite(): ?int;

    public function getDefaultBrowseMaxDepth(): int;

    public function isAutoDetectWriteType(): bool;

    public function isReadMetadataCache(): bool;

    public function getExtensionObjectRepository(): ExtensionObjectRepository;

    /** @return array<string, class-string<\BackedEnum>> */
    public function getEnumMappings(): array;
}
