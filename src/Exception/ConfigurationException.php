<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Exception;

/**
 * Thrown when the client configuration is invalid, such as a malformed endpoint URL or missing certificates.
 */
class ConfigurationException extends OpcUaException
{
}
