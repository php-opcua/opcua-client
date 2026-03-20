<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Encoding;

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

/**
 * Maps OPC UA DataType NodeIds (namespace 0) to their corresponding BuiltinType.
 */
final class DataTypeMapping
{
    /** @var array<int, BuiltinType> */
    private static array $map = [
        1  => BuiltinType::Boolean,
        2  => BuiltinType::SByte,
        3  => BuiltinType::Byte,
        4  => BuiltinType::Int16,
        5  => BuiltinType::UInt16,
        6  => BuiltinType::Int32,
        7  => BuiltinType::UInt32,
        8  => BuiltinType::Int64,
        9  => BuiltinType::UInt64,
        10 => BuiltinType::Float,
        11 => BuiltinType::Double,
        12 => BuiltinType::String,
        13 => BuiltinType::DateTime,
        14 => BuiltinType::Guid,
        15 => BuiltinType::ByteString,
        17 => BuiltinType::NodeId,
        20 => BuiltinType::QualifiedName,
        21 => BuiltinType::LocalizedText,
        22 => BuiltinType::ExtensionObject,
        24 => BuiltinType::Variant,
    ];

    /**
     * Resolve a DataType NodeId to a BuiltinType.
     *
     * @param NodeId $dataType The DataType NodeId to resolve.
     * @return BuiltinType|null The matching BuiltinType, or null if the DataType is a custom structured type.
     */
    public static function resolve(NodeId $dataType): ?BuiltinType
    {
        if ($dataType->namespaceIndex !== 0 || !$dataType->isNumeric()) {
            return null;
        }

        return self::$map[$dataType->identifier] ?? null;
    }
}
