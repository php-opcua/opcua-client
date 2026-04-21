<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when a cache entry payload is malformed or rejected by the wire allowlist.
 */
class CacheCorruptedException extends OpcUaException
{
}
