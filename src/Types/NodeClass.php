<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

enum NodeClass: int
{
    case Unspecified = 0;
    case Object = 1;
    case Variable = 2;
    case Method = 4;
    case ObjectType = 8;
    case VariableType = 16;
    case ReferenceType = 32;
    case DataType = 64;
    case View = 128;
}
