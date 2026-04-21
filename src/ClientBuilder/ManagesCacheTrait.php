<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ClientBuilder;

use PhpOpcua\Client\Cache\CacheCodecInterface;
use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Cache\WireCacheCodec;
use PhpOpcua\Client\Wire\CoreWireTypes;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use Psr\SimpleCache\CacheInterface;

/**
 * Provides cache configuration using a PSR-16 cache backend.
 */
trait ManagesCacheTrait
{
    private ?CacheInterface $cache = null;

    private bool $cacheInitialized = false;

    private ?CacheCodecInterface $cacheCodec = null;

    /**
     * Set the cache driver. Pass null to disable caching entirely.
     *
     * @param ?CacheInterface $cache A PSR-16 cache instance, or null to disable.
     * @return self
     */
    public function setCache(?CacheInterface $cache): self
    {
        $this->cache = $cache;
        $this->cacheInitialized = true;

        return $this;
    }

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
     * Override the cache value codec. Pass null to use the default {@see WireCacheCodec}.
     *
     * @param ?CacheCodecInterface $codec
     * @return self
     */
    public function setCacheCodec(?CacheCodecInterface $codec): self
    {
        $this->cacheCodec = $codec;

        return $this;
    }

    /**
     * @return CacheCodecInterface
     */
    public function getCacheCodec(): CacheCodecInterface
    {
        if ($this->cacheCodec === null) {
            $registry = new WireTypeRegistry();
            CoreWireTypes::registerForCache($registry);
            $this->cacheCodec = new WireCacheCodec($registry);
        }

        return $this->cacheCodec;
    }

    /**
     * Initializes the cache with a default InMemoryCache if not yet configured.
     *
     * @return void
     */
    private function ensureCacheInitialized(): void
    {
        if (! $this->cacheInitialized) {
            $this->cache = new InMemoryCache(300);
            $this->cacheInitialized = true;
        }
    }
}
