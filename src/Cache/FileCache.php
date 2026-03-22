<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * File-based PSR-16 cache implementation. Data survives PHP process restarts.
 *
 * Each cache entry is stored as a serialized file in the specified directory.
 *
 * @implements CacheInterface
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

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!file_exists($path)) {
            return $default;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return $default;
        }

        $entry = @unserialize($raw);
        if ($entry === false) {
            @unlink($path);
            return $default;
        }

        if (isset($entry['expiresAt']) && $entry['expiresAt'] < time()) {
            @unlink($path);
            return $default;
        }

        return $entry['value'] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->resolveTtl($ttl);

        $entry = [
            'value' => $value,
            'expiresAt' => $seconds > 0 ? time() + $seconds : null,
        ];

        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents($path, serialize($entry), LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }

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

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @return int
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
    }

    private function resolveTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof \DateInterval) {
            return (int)(new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }

        return $ttl;
    }
}
