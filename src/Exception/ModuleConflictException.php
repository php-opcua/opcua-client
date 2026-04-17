<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when two modules attempt to register a method with the same name.
 *
 * Use {@see \PhpOpcua\Client\ClientBuilder::replaceModule()} to intentionally swap a module.
 */
class ModuleConflictException extends OpcUaException
{
}
