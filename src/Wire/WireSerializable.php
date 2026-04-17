<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Wire;

use JsonSerializable;

/**
 * Contract for value-objects that can travel across a JSON-based IPC boundary
 * without using `unserialize()`.
 *
 * The round-trip invariant is `Foo::fromWireArray($foo->jsonSerialize())` returns
 * a value-equal instance. The enclosing {@see WireTypeRegistry} wraps each
 * emitted payload with the `__t` discriminator and rejects discriminators that
 * are not registered, so only explicitly registered classes can be instantiated.
 */
interface WireSerializable extends JsonSerializable
{
    /**
     * Produce the wire payload for this value. MUST NOT include `__t`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;

    /**
     * Reconstruct an instance from the array emitted by {@see self::jsonSerialize()}.
     *
     * Nested {@see WireSerializable} values are already decoded by the registry
     * before this method is called. Enum values arrive as their backing scalar.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromWireArray(array $data): static;

    /**
     * Stable short identifier used as the `__t` discriminator on the wire.
     * Must be unique within a given {@see WireTypeRegistry} instance.
     *
     * @return string
     */
    public static function wireTypeId(): string;
}
