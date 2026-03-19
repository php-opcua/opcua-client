<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
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
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browse(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0): array
    {
        return $this->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
            $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

            return $this->browseService->decodeBrowseResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function browseWithContinuation(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0): array
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
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return ReferenceDescription[]
     */
    public function browseAll(
        NodeId          $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        int             $nodeClassMask = 0,
    ): array
    {
        $result = $this->browseWithContinuation($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);
        $allRefs = $result['references'];

        while ($result['continuationPoint'] !== null) {
            $result = $this->browseNext($result['continuationPoint']);
            array_push($allRefs, ...$result['references']);
        }

        return $allRefs;
    }

    private const MAX_BROWSE_RECURSIVE_DEPTH = 256;

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?int $maxDepth
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return BrowseNode[]
     */
    public function browseRecursive(
        NodeId          $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int            $maxDepth = null,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        int             $nodeClassMask = 0,
    ): array
    {
        $resolvedDepth = $maxDepth ?? $this->getDefaultBrowseMaxDepth();
        $effectiveMaxDepth = $resolvedDepth === -1
            ? self::MAX_BROWSE_RECURSIVE_DEPTH
            : min($resolvedDepth, self::MAX_BROWSE_RECURSIVE_DEPTH);

        $visited = [];

        return $this->browseRecursiveInternal($nodeId, $effectiveMaxDepth, 1, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask, $visited);
    }

    /**
     * @param NodeId $nodeId
     * @param int $maxDepth
     * @param int $currentDepth
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @param array<string, true> $visited
     * @return BrowseNode[]
     */
    private function browseRecursiveInternal(
        NodeId          $nodeId,
        int             $maxDepth,
        int             $currentDepth,
        BrowseDirection $direction,
        ?NodeId         $referenceTypeId,
        bool            $includeSubtypes,
        int             $nodeClassMask,
        array           &$visited,
    ): array
    {
        $references = $this->browseAll($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);
        $nodes = [];

        foreach ($references as $ref) {
            $nodeKey = $ref->getNodeId()->__toString();

            // Cycle detection: skip already visited nodes
            if (isset($visited[$nodeKey])) {
                $nodes[] = new BrowseNode($ref);
                continue;
            }

            $node = new BrowseNode($ref);
            $visited[$nodeKey] = true;

            if ($currentDepth < $maxDepth) {
                $children = $this->browseRecursiveInternal(
                    $ref->getNodeId(),
                    $maxDepth,
                    $currentDepth + 1,
                    $direction,
                    $referenceTypeId,
                    $includeSubtypes,
                    $nodeClassMask,
                    $visited,
                );
                foreach ($children as $child) {
                    $node->addChild($child);
                }
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param NodeId|null $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     * @return BinaryDecoder
     */
    public function getBinaryDecoder(NodeId $nodeId, BrowseDirection $direction, ?NodeId $referenceTypeId, bool $includeSubtypes, int $nodeClassMask): BinaryDecoder
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
