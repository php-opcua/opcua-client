<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * OPC UA built-in data type identifiers as defined in Part 6.
 */
enum BuiltinType: int
{
    case Boolean = 1;
    case SByte = 2;
    case Byte = 3;
    case Int16 = 4;
    case UInt16 = 5;
    case Int32 = 6;
    case UInt32 = 7;
    case Int64 = 8;
    case UInt64 = 9;
    case Float = 10;
    case Double = 11;
    case String = 12;
    case DateTime = 13;
    case Guid = 14;
    case ByteString = 15;
    case XmlElement = 16;
    case NodeId = 17;
    case ExpandedNodeId = 18;
    case StatusCode = 19;
    case QualifiedName = 20;
    case LocalizedText = 21;
    case ExtensionObject = 22;
    case DataValue = 23;
    case Variant = 24;
    case DiagnosticInfo = 25;
}
