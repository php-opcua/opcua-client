<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when a cryptographic signature verification fails on a received message.
 */
class SignatureVerificationException extends SecurityException
{
}
