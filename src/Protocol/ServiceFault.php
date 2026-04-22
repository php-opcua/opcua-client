<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

/**
 * Detects and surfaces top-level OPC UA ServiceFault responses.
 *
 * @see AbstractProtocolService::readResponseMetadata()
 * @see SessionService::decodeCreateSessionResponse()
 * @see SessionService::decodeActivateSessionResponse()
 */
final class ServiceFault
{
    /**
     * If the given TypeId identifies a ServiceFault, throw a typed exception
     * carrying the ResponseHeader's ServiceResult.
     *
     * @param NodeId $typeId        Type NodeId read from the response envelope.
     * @param int    $serviceResult Status code from the ResponseHeader.
     *
     * @throws ServiceUnsupportedException when `$serviceResult` is `BadServiceUnsupported`.
     * @throws ServiceException            for any other ServiceFault status.
     */
    public static function throwIf(NodeId $typeId, int $serviceResult): void
    {
        if ($typeId->namespaceIndex !== 0 || $typeId->identifier !== ServiceTypeId::SERVICE_FAULT) {
            return;
        }

        $message = sprintf(
            'Server returned ServiceFault: 0x%08X %s',
            $serviceResult,
            StatusCode::getName($serviceResult),
        );

        if ($serviceResult === StatusCode::BadServiceUnsupported) {
            throw new ServiceUnsupportedException($message, $serviceResult);
        }

        throw new ServiceException($message, $serviceResult);
    }
}
