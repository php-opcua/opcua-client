<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when an OpenSSL function returns false, indicating a low-level cryptographic failure.
 */
class OpenSslException extends SecurityException
{
}
