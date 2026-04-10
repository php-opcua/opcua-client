<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Security;

use PhpOpcua\Client\Exception\OpenSslException;

/**
 * Trait for asserting OpenSSL function results are not false.
 */
trait EnsuresOpenSslSuccess
{
    /**
     * @template T
     *
     * @param T|false $result
     * @param string $message
     * @return T
     *
     * @throws OpenSslException
     */
    private static function ensureNotFalse(mixed $result, string $message): mixed
    {
        if ($result === false) {
            throw new OpenSslException("{$message}: " . openssl_error_string());
        }

        return $result;
    }
}
