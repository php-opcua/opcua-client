<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Security;

enum SecurityMode: int
{
    case None = 1;
    case Sign = 2;
    case SignAndEncrypt = 3;
}
