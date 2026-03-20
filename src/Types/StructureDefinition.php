<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * Describes the structure of an OPC UA custom data type, including its fields and encoding.
 *
 * @see StructureField
 */
readonly class StructureDefinition
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
        public int    $structureType,
        public array  $fields,
        public NodeId $defaultEncodingId,
    )
    {
    }
}
