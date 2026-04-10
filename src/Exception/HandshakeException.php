<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when the HEL/ACK handshake or discovery phase fails with a server error.
 */
class HandshakeException extends ProtocolException
{
    /**
     * @param int $errorCode The OPC UA error code from the ERR message.
     * @param string $errorMessage The error reason from the server.
     */
    public function __construct(
        public readonly int $errorCode,
        string $errorMessage,
    ) {
        parent::__construct("Server error during handshake: [{$errorCode}] {$errorMessage}");
    }
}
