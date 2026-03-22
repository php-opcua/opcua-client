<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Exception;

/**
 * Thrown when a NodeId string cannot be parsed into a valid node identifier.
 */
class InvalidNodeIdException extends OpcUaException
{
}
