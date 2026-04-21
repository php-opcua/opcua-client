<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Cache\CacheCodecInterface;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Event\CacheHit;
use PhpOpcua\Client\Event\CacheMiss;
use PhpOpcua\Client\Exception\CacheCorruptedException;
use PhpOpcua\Client\Types\NodeId;
use Psr\SimpleCache\CacheInterface;

/**
 * Provides runtime cache operations for the connected client.
 *
 * Handles cache lookups, invalidation, flushing, and key generation. The cache
 * is lazily initialized with an {@see InMemoryCache} if no explicit cache was
 * configured via the builder.
 */
trait ManagesCacheRuntimeTrait
{
    /**
     * Get the current cache driver, or null if caching is disabled.
     *
     * @return ?CacheInterface
     */
    public function getCache(): ?CacheInterface
    {
        $this->ensureCacheInitialized();

        return $this->cache;
    }

    /**
     * @return CacheCodecInterface
     */
    public function getCacheCodec(): CacheCodecInterface
    {
        return $this->cacheCodec;
    }

    /**
     * Invalidate cached browse results for a specific node.
     *
     * @param NodeId|string $nodeId The node whose cache entries should be invalidated.
     * @return void
     */
    public function invalidateCache(NodeId|string $nodeId): void
    {
        $this->ensureCacheInitialized();
        if ($this->cache === null) {
            return;
        }

        $nodeId = $this->resolveNodeId($nodeId);
        $prefix = $this->buildCacheKeyPrefix($nodeId);

        if ($this->cache instanceof InMemoryCache) {
            $this->invalidateByPrefix($prefix);

            return;
        }

        $this->cache->delete($prefix . ':browse');
        $this->cache->delete($prefix . ':browseAll');
        $this->cache->delete($prefix . ':writeType');

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 15, 16, 17, 18, 19, 20, 21, 22, 26] as $attr) {
            $this->cache->delete($prefix . ':readMeta:' . $attr);
        }
    }

    /**
     * Flush the entire cache.
     *
     * @return void
     */
    public function flushCache(): void
    {
        $this->ensureCacheInitialized();
        if ($this->cache === null) {
            return;
        }
        $this->cache->clear();
    }

    /**
     * Build a cache key for a node-scoped entry.
     *
     * @param string $type The cache entry type.
     * @param NodeId $nodeId The target node.
     * @param string $paramsSuffix Additional parameters suffix.
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
     * Build a cache key prefix for node-level invalidation.
     *
     * @param NodeId $nodeId The target node.
     * @return string
     */
    private function buildCacheKeyPrefix(NodeId $nodeId): string
    {
        $endpointHash = md5($this->lastEndpointUrl ?? 'unknown');

        return sprintf('opcua:%s:%s', $endpointHash, $nodeId->__toString());
    }

    /**
     * Build a simple cache key without a node scope.
     *
     * @param string $type The cache entry type.
     * @param string $paramsSuffix Additional parameters suffix.
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
     * Fetch a value from cache or compute it via the fetcher callable.
     *
     * @param string $key The cache key.
     * @param callable $fetcher The callable that produces the value on cache miss.
     * @param bool $useCache Whether to use cache for this fetch.
     * @return mixed
     */
    public function cachedFetch(string $key, callable $fetcher, bool $useCache): mixed
    {
        $this->ensureCacheInitialized();

        if ($useCache && $this->cache !== null) {
            $raw = $this->cache->get($key);
            $cached = $this->decodeCacheValue($key, $raw);
            if ($cached !== null) {
                $this->dispatch(fn () => new CacheHit($this, $key));

                return $cached;
            }
            $this->dispatch(fn () => new CacheMiss($this, $key));
        }

        $result = $fetcher();

        if ($useCache && $this->cache !== null) {
            $this->cache->set($key, $this->cacheCodec->encode($result));
        }

        return $result;
    }

    /**
     * @param string $key
     * @param mixed $raw
     * @return mixed
     */
    private function decodeCacheValue(string $key, mixed $raw): mixed
    {
        if ($raw === null || ! is_string($raw)) {
            return null;
        }

        try {
            return $this->cacheCodec->decode($raw);
        } catch (CacheCorruptedException) {
            $this->cache?->delete($key);

            return null;
        }
    }

    /**
     * Initialize the cache with a default InMemoryCache if not yet configured.
     *
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
     * Delete all InMemoryCache entries whose keys start with the given prefix.
     *
     * @param string $prefix The key prefix to match.
     * @return void
     */
    private function invalidateByPrefix(string $prefix): void
    {
        if ($this->cache instanceof InMemoryCache) {
            $this->cache->deleteByPrefix($prefix);
        }
    }
}
