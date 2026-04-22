<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Exception;

/**
 * Thrown when the server responds with a ServiceFault carrying
 * `BadServiceUnsupported (0x800B0000)`.
 *
 * Signals that the specific OPC UA service the caller invoked is not
 * implemented by the connected server — not a transient error, not a per-item
 * validation failure, but a capability gap. The typical remedy is to stop
 * calling the method on that endpoint (or switch to a server that implements
 * the service set).
 *
 * Extends `ServiceException`, so existing handlers catching `ServiceException`
 * continue to work.
 *
 * @see \PhpOpcua\Client\Protocol\ServiceFault::throwIf()
 */
class ServiceUnsupportedException extends ServiceException
{
}
