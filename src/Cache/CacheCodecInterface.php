<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Cache;

use PhpOpcua\Client\Exception\CacheCorruptedException;
use PhpOpcua\Client\Exception\EncodingException;

/**
 * Contract for a cache value codec. Implementations MUST NOT call
 * `unserialize()` on cache payloads.
 */
interface CacheCodecInterface
{
    /**
     * @param mixed $value
     * @return string
     * @throws EncodingException
     */
    public function encode(mixed $value): string;

    /**
     * @param string $raw
     * @return mixed
     * @throws CacheCorruptedException
     */
    public function decode(string $raw): mixed;
}
