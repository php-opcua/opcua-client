<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache implementation. Data is lost when the PHP process ends.
 */
class InMemoryCache implements CacheInterface
{
    use SimpleCacheTrait;

    /** @var array<string, array{value: mixed, expiresAt: ?float}> */
    private array $store = [];

    /**
     * @param int $defaultTtl Default time-to-live in seconds. 0 means no expiration.
     */
    public function __construct(int $defaultTtl = 300)
    {
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! isset($this->store[$key])) {
            return $default;
        }

        $entry = $this->store[$key];

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] < microtime(true)) {
            unset($this->store[$key]);

            return $default;
        }

        return $entry['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->resolveTtl($ttl);

        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $seconds > 0 ? microtime(true) + $seconds : null,
        ];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * Delete all entries whose key starts with the given prefix.
     *
     * @param string $prefix
     * @return void
     */
    public function deleteByPrefix(string $prefix): void
    {
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->store[$key]);
            }
        }
    }
}
