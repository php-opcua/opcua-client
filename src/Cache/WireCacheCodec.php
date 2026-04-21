<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Cache;

use JsonException;
use PhpOpcua\Client\Exception\CacheCorruptedException;
use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Default {@see CacheCodecInterface} backed by the project's
 * {@see WireTypeRegistry}. Values are serialised as allowlist-gated JSON, never
 * via `serialize()`/`unserialize()`.
 */
final readonly class WireCacheCodec implements CacheCodecInterface
{
    private const PAYLOAD_PREFIX = 'opcua.wire.v1:';

    /**
     * @param WireTypeRegistry $registry
     */
    public function __construct(
        private WireTypeRegistry $registry,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function encode(mixed $value): string
    {
        $walked = $this->registry->encode($value);

        try {
            $json = json_encode($walked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new EncodingException('Cannot encode cache value as JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::PAYLOAD_PREFIX . $json;
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $raw): mixed
    {
        if (! str_starts_with($raw, self::PAYLOAD_PREFIX)) {
            throw new CacheCorruptedException('Cache payload missing wire prefix.');
        }

        $json = substr($raw, strlen(self::PAYLOAD_PREFIX));

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CacheCorruptedException('Cache payload is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        try {
            return $this->registry->decode($decoded);
        } catch (EncodingException $e) {
            throw new CacheCorruptedException('Cache payload failed wire allowlist check: ' . $e->getMessage(), 0, $e);
        }
    }
}
