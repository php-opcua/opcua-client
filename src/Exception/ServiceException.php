<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Exception;

use Throwable;

/**
 * Thrown when an OPC UA service call returns a bad status code.
 */
class ServiceException extends OpcUaException
{
    private int $statusCode;

    /**
     * @param string $message
     * @param int $statusCode
     * @param ?Throwable $previous
     */
    public function __construct(string $message, int $statusCode = 0, ?Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
