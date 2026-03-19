<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

trait ManagesBrowseTrait
{
    /**
     * @param string $endpointUrl
     * @return EndpointDescription[]
     */
    public function getEndpoints(string $endpointUrl): array
    {
        return $this->executeWithRetry(function () use ($endpointUrl) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $authToken = $this->authenticationToken ?? NodeId::numeric(0, 0);
            $request = $this->getEndpointsService->encodeGetEndpointsRequest($requestId, $endpointUrl, $authToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->getEndpointsService->decodeGetEndpointsResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param int $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browse(NodeId $nodeId, int $direction = 0, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0): array
    {
        return $this->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
            $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

            return $this->browseService->decodeBrowseResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param int $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseWithContinuation(NodeId $nodeId, int $direction = 0, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0): array
    {
        return $this->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
            $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

            return $this->browseService->decodeBrowseResponseWithContinuation($decoder);
        });
    }

    /**
     * @param string $continuationPoint
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseNext(string $continuationPoint): array
    {
        return $this->executeWithRetry(function () use ($continuationPoint) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->browseService->encodeBrowseNextRequest($requestId, $continuationPoint, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->browseService->decodeBrowseNextResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param int $direction
     * @param NodeId|null $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return BinaryDecoder
     */
    public function getBinaryDecoder(NodeId $nodeId, int $direction, ?NodeId $referenceTypeId, bool $includeSubtypes, int $nodeClassMask): BinaryDecoder
    {
        $this->ensureConnected();

        $requestId = $this->nextRequestId();
        $request = $this->browseService->encodeBrowseRequest(
            $requestId,
            $nodeId,
            $this->authenticationToken,
            $direction,
            $referenceTypeId,
            $includeSubtypes,
            $nodeClassMask,
        );
        $this->transport->send($request);

        $response = $this->transport->receive();
        $responseBody = $this->unwrapResponse($response);
        return new BinaryDecoder($responseBody);
    }
}
