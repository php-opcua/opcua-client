<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Types;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Describes a single field within an OPC UA structured data type.
 *
 * @see StructureDefinition
 */
readonly class StructureField implements WireSerializable
{
    /**
     * @param string $name The field name.
     * @param NodeId $dataType The DataType NodeId of this field.
     * @param int $valueRank -1 for scalar, 0 or higher for array.
     * @param bool $isOptional Whether this field is optional (used with StructureWithOptionalFields).
     */
    public function __construct(
        public string $name,
        public NodeId $dataType,
        public int $valueRank = -1,
        public bool $isOptional = false,
    ) {
    }

    /**
     * @return array{name: string, dataType: NodeId, valueRank: int, isOptional: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'dataType' => $this->dataType,
            'valueRank' => $this->valueRank,
            'isOptional' => $this->isOptional,
        ];
    }

    /**
     * @param array{name?: mixed, dataType?: mixed, valueRank?: mixed, isOptional?: mixed} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['name']) || ! is_string($data['name'])) {
            throw new EncodingException('StructureField wire payload: "name" must be a string.');
        }
        if (! isset($data['dataType']) || ! $data['dataType'] instanceof NodeId) {
            throw new EncodingException('StructureField wire payload: "dataType" must be a decoded NodeId instance.');
        }
        if (! isset($data['valueRank']) || ! is_int($data['valueRank'])) {
            throw new EncodingException('StructureField wire payload: "valueRank" must be an int.');
        }
        if (! array_key_exists('isOptional', $data) || ! is_bool($data['isOptional'])) {
            throw new EncodingException('StructureField wire payload: "isOptional" must be a bool.');
        }

        return new self($data['name'], $data['dataType'], $data['valueRank'], $data['isOptional']);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'StructureField';
    }
}
