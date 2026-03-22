<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StructureDefinition;
use Gianfriaur\OpcuaPhpClient\Types\StructureField;

/**
 * A generic ExtensionObjectCodec that decodes/encodes based on a StructureDefinition.
 *
 * Created automatically by the type discovery system. Handles Structure, StructureWithOptionalFields, and Union types.
 *
 * @see StructureDefinition
 */
class DynamicCodec implements ExtensionObjectCodec
{
    /**
     * @param StructureDefinition $definition The structure definition describing the fields.
     */
    public function __construct(
        private readonly StructureDefinition $definition,
    )
    {
    }

    /**
     * Returns the structure definition used by this codec.
     *
     * @return StructureDefinition
     */
    public function getDefinition(): StructureDefinition
    {
        return $this->definition;
    }

    /**
     * Decode the binary body of an ExtensionObject into an associative array.
     *
     * @param BinaryDecoder $decoder The decoder positioned at the start of the body.
     * @return array<string, mixed> Associative array keyed by field name.
     */
    public function decode(BinaryDecoder $decoder): array
    {
        $optionalMask = 0;
        if ($this->definition->structureType === StructureDefinition::WITH_OPTIONAL_FIELDS) {
            $optionalMask = $decoder->readUInt32();
        }

        if ($this->definition->structureType === StructureDefinition::UNION) {
            $switchField = $decoder->readUInt32();
            if ($switchField === 0) {
                return ['_switchField' => 0];
            }

            foreach ($this->definition->fields as $i => $field) {
                if (($i + 1) === $switchField) {
                    return [
                        '_switchField' => $switchField,
                        $field->name => $this->decodeField($decoder, $field),
                    ];
                }
            }

            return ['_switchField' => $switchField];
        }

        $result = [];
        $optionalIndex = 0;

        foreach ($this->definition->fields as $field) {
            if ($this->definition->structureType === StructureDefinition::WITH_OPTIONAL_FIELDS && $field->isOptional) {
                if (!(($optionalMask >> $optionalIndex) & 1)) {
                    $result[$field->name] = null;
                    $optionalIndex++;
                    continue;
                }
                $optionalIndex++;
            }

            $result[$field->name] = $this->decodeField($decoder, $field);
        }

        return $result;
    }

    /**
     * Encode an associative array back into binary.
     *
     * @param BinaryEncoder $encoder The encoder to write to.
     * @param mixed $value The associative array to encode.
     * @return void
     */
    public function encode(BinaryEncoder $encoder, mixed $value): void
    {
        if ($this->definition->structureType === StructureDefinition::WITH_OPTIONAL_FIELDS) {
            $mask = 0;
            $optionalIndex = 0;
            foreach ($this->definition->fields as $field) {
                if ($field->isOptional) {
                    if (isset($value[$field->name]) && $value[$field->name] !== null) {
                        $mask |= (1 << $optionalIndex);
                    }
                    $optionalIndex++;
                }
            }
            $encoder->writeUInt32($mask);
        }

        if ($this->definition->structureType === StructureDefinition::UNION) {
            $switchField = $value['_switchField'] ?? 0;
            $encoder->writeUInt32($switchField);
            if ($switchField === 0) {
                return;
            }
            foreach ($this->definition->fields as $i => $field) {
                if (($i + 1) === $switchField && isset($value[$field->name])) {
                    $this->encodeField($encoder, $field, $value[$field->name]);
                }
            }
            return;
        }

        $optionalIndex = 0;
        foreach ($this->definition->fields as $field) {
            if ($this->definition->structureType === StructureDefinition::WITH_OPTIONAL_FIELDS && $field->isOptional) {
                if (!isset($value[$field->name]) || $value[$field->name] === null) {
                    $optionalIndex++;
                    continue;
                }
                $optionalIndex++;
            }

            $this->encodeField($encoder, $field, $value[$field->name] ?? null);
        }
    }

    /**
     * @param BinaryDecoder $decoder
     * @param StructureField $field
     * @return mixed
     */
    private function decodeField(BinaryDecoder $decoder, StructureField $field): mixed
    {
        $builtinType = DataTypeMapping::resolve($field->dataType);

        if ($field->valueRank >= 0) {
            $length = $decoder->readInt32();
            $arr = [];
            for ($j = 0; $j < $length; $j++) {
                $arr[] = $this->decodeSingleValue($decoder, $builtinType);
            }
            return $arr;
        }

        return $this->decodeSingleValue($decoder, $builtinType);
    }

    /**
     * @param BinaryDecoder $decoder
     * @param BuiltinType|null $type
     * @return mixed
     */
    private function decodeSingleValue(BinaryDecoder $decoder, ?BuiltinType $type): mixed
    {
        if ($type === null) {
            return $decoder->readExtensionObject();
        }

        return $decoder->readVariantValue($type);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param StructureField $field
     * @param mixed $value
     * @return void
     */
    private function encodeField(BinaryEncoder $encoder, StructureField $field, mixed $value): void
    {
        $builtinType = DataTypeMapping::resolve($field->dataType);

        if ($field->valueRank >= 0) {
            $arr = is_array($value) ? $value : [];
            $encoder->writeInt32(count($arr));
            foreach ($arr as $item) {
                $this->encodeSingleValue($encoder, $builtinType, $item);
            }
            return;
        }

        $this->encodeSingleValue($encoder, $builtinType, $value);
    }

    /**
     * @param BinaryEncoder $encoder
     * @param BuiltinType|null $type
     * @param mixed $value
     * @return void
     */
    private function encodeSingleValue(BinaryEncoder $encoder, ?BuiltinType $type, mixed $value): void
    {
        if ($type === null) {
            return;
        }

        $encoder->writeVariantValue($type, $value);
    }
}
