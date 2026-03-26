<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache implementation. Data is lost when the PHP process ends.
 *
 * @implements CacheInterface<mixed>
 */
class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: ?float}> */
    private array $store = [];

    private int $defaultTtl;

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
     * {@inheritDoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
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

    /**
     * Returns the default time-to-live in seconds.
     *
     * @return int
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Resolves a TTL value to an integer number of seconds.
     *
     * @param null|int|\DateInterval $ttl
     * @return int
     */
    private function resolveTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof \DateInterval) {
            return (int) (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }

        return $ttl;
    }
}
