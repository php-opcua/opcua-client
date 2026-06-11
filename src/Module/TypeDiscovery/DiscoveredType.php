<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TypeDiscovery;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StructureDefinition;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * A custom data type discovered on the server: its Default Binary encoding
 * NodeId plus the structure definition needed to build a codec for it.
 *
 * @see TypeDiscoveryModule::discoverDataTypes()
 */
final readonly class DiscoveredType implements WireSerializable
{
    public function __construct(
        public NodeId $encodingId,
        public StructureDefinition $definition,
    ) {
    }

    /**
     * @return array{enc: NodeId, def: StructureDefinition}
     */
    public function jsonSerialize(): array
    {
        return ['enc' => $this->encodingId, 'def' => $this->definition];
    }

    /**
     * @param array<string, mixed> $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $encodingId = $data['enc'] ?? null;
        $definition = $data['def'] ?? null;
        if (! $encodingId instanceof NodeId || ! $definition instanceof StructureDefinition) {
            throw new EncodingException('DiscoveredType wire payload: expected decoded NodeId "enc" and StructureDefinition "def".');
        }

        return new self($encodingId, $definition);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'DiscoveredType';
    }
}
