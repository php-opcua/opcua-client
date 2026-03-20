<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * Describes a single field within an OPC UA structured data type.
 *
 * @see StructureDefinition
 */
readonly class StructureField
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
        public int    $valueRank = -1,
        public bool   $isOptional = false,
    )
    {
    }
}
