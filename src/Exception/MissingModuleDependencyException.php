<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when a module declares a dependency on another module that is not registered.
 */
class MissingModuleDependencyException extends OpcUaException
{
}
