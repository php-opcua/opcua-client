<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Security;

/**
 * OPC UA message security mode.
 */
enum SecurityMode: int
{
    case None = 1;
    case Sign = 2;
    case SignAndEncrypt = 3;
}
