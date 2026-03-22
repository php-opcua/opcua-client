<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * Client connection lifecycle states.
 */
enum ConnectionState
{
    case Disconnected;
    case Connected;
    case Broken;
}
