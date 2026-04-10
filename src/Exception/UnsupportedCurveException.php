<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when an unsupported elliptic curve name is used for ECC operations.
 */
class UnsupportedCurveException extends SecurityException
{
    /**
     * @param string $curveName The unsupported OpenSSL curve name.
     */
    public function __construct(public readonly string $curveName)
    {
        parent::__construct("Unsupported curve: {$curveName}");
    }
}
