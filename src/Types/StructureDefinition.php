<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Describes the structure of an OPC UA custom data type, including its fields and encoding.
 *
 * @see StructureField
 */
readonly class StructureDefinition implements WireSerializable
{
    public const STRUCTURE = 0;

    public const WITH_OPTIONAL_FIELDS = 1;

    public const UNION = 2;

    /**
     * @param int $structureType 0=Structure, 1=StructureWithOptionalFields, 2=Union.
     * @param StructureField[] $fields The fields of this structure.
     * @param NodeId $defaultEncodingId The binary encoding NodeId for this type.
     */
    public function __construct(
        public int $structureType,
        public array $fields,
        public NodeId $defaultEncodingId,
    ) {
    }

    /**
     * @return array{structureType: int, fields: StructureField[], defaultEncodingId: NodeId}
     */
    public function jsonSerialize(): array
    {
        return [
            'structureType' => $this->structureType,
            'fields' => $this->fields,
            'defaultEncodingId' => $this->defaultEncodingId,
        ];
    }

    /**
     * @param array{structureType?: mixed, fields?: mixed, defaultEncodingId?: mixed} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['structureType']) || ! is_int($data['structureType'])) {
            throw new EncodingException('StructureDefinition wire payload: "structureType" must be an int.');
        }
        if (! isset($data['defaultEncodingId']) || ! $data['defaultEncodingId'] instanceof NodeId) {
            throw new EncodingException('StructureDefinition wire payload: "defaultEncodingId" must be a decoded NodeId instance.');
        }

        $fields = [];
        foreach ($data['fields'] ?? [] as $field) {
            if (! $field instanceof StructureField) {
                throw new EncodingException('StructureDefinition wire payload: "fields" must contain decoded StructureField instances.');
            }
            $fields[] = $field;
        }

        return new self($data['structureType'], $fields, $data['defaultEncodingId']);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'StructureDefinition';
    }
}
