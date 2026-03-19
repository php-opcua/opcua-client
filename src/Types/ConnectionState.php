<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

enum ConnectionState
{
    case Disconnected;
    case Connected;
    case Broken;
}
