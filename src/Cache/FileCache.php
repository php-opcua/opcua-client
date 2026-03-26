<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * File-based PSR-16 cache implementation. Data survives PHP process restarts.
 *
 * Each cache entry is stored as a serialized file in the specified directory.
 *
 * @implements CacheInterface<mixed>
 */
class FileCache implements CacheInterface
{
    private string $directory;

    private int $defaultTtl;

    /**
     * @param string $directory Path to the cache directory. Created if it does not exist.
     * @param int $defaultTtl Default time-to-live in seconds. 0 means no expiration.
     */
    public function __construct(string $directory, int $defaultTtl = 300)
    {
        $this->directory = rtrim($directory, '/');
        $this->defaultTtl = $defaultTtl;

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return $default;
        }

        $entry = $this->readEntry($path);
        if ($entry === null) {
            return $default;
        }

        if (isset($entry['expiresAt']) && $entry['expiresAt'] < time()) {
            @unlink($path);

            return $default;
        }

        return $entry['value'] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->resolveTtl($ttl);

        $entry = [
            'value' => $value,
            'expiresAt' => $seconds > 0 ? time() + $seconds : null,
        ];

        $path = $this->path($key);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents($path, serialize($entry), LOCK_EX) !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if (file_exists($path)) {
            return @unlink($path);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        $files = glob($this->directory . '/*.cache');
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            @unlink($file);
        }

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
     * Returns the default time-to-live in seconds.
     *
     * @return int
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Read and unserialize a cache entry from disk.
     *
     * @param string $path
     * @return ?array
     */
    private function readEntry(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw);
        if ($entry === false) {
            @unlink($path);

            return null;
        }

        return $entry;
    }

    /**
     * Returns the filesystem path for the given cache key.
     *
     * @param string $key
     * @return string
     */
    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
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
