<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when the server responds with an unexpected OPC UA message type.
 */
class MessageTypeException extends ProtocolException
{
    /**
     * @param string $expected The expected message type (e.g. 'OPN', 'ACK').
     * @param string $actual The actual message type received.
     */
    public function __construct(
        public readonly string $expected,
        public readonly string $actual,
    ) {
        parent::__construct("Expected {$expected} response, got: {$actual}");
    }
}
