<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;
use Gianfriaur\OpcuaPhpClient\Types\StructureField;

/**
 * Parses the binary body of an OPC UA StructureDefinition ExtensionObject (TypeId ns=0;i=122).
 */
final class StructureDefinitionParser
{
    /**
     * Parse a StructureDefinition from a BinaryDecoder positioned at the start of the body.
     *
     * @param BinaryDecoder $decoder The decoder positioned at the StructureDefinition body.
     * @return StructureDefinition The parsed definition.
     */
    public static function parse(BinaryDecoder $decoder): StructureDefinition
    {
        $defaultEncodingId = $decoder->readNodeId();
        $decoder->readNodeId(); // baseDataType (not stored)
        $structureType = $decoder->readUInt32();

        $fieldCount = $decoder->readInt32();
        $fields = [];

        for ($i = 0; $i < $fieldCount; $i++) {
            $name = $decoder->readString();
            $decoder->readLocalizedText(); // description (not stored)
            $dataType = $decoder->readNodeId();
            $valueRank = $decoder->readInt32();

            $arrayDimCount = $decoder->readInt32();
            for ($j = 0; $j < $arrayDimCount; $j++) {
                $decoder->readUInt32(); // arrayDimensions (not stored)
            }

            $decoder->readUInt32(); // maxStringLength (not stored)
            $isOptional = $decoder->readBoolean();

            $fields[] = new StructureField($name, $dataType, $valueRank, $isOptional);
        }

        return new StructureDefinition($structureType, $fields, $defaultEncodingId);
    }
}
