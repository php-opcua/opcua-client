<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * Browse direction for address space navigation.
 */
enum BrowseDirection: int
{
    case Forward = 0;
    case Inverse = 1;
    case Both = 2;
}
