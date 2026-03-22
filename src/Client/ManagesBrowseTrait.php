<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

/**
 * Provides browse and endpoint discovery operations for the OPC UA address space.
 */
trait ManagesBrowseTrait
{
    /**
     * Discover endpoints available at the given server URL.
     *
     * @param string $endpointUrl The OPC UA endpoint URL to query.
     * @return EndpointDescription[]
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        $cacheKey = $this->buildSimpleCacheKey('endpoints', md5($endpointUrl));

        return $this->cachedFetch(
            $cacheKey,
            fn() => $this->executeWithRetry(function () use ($endpointUrl) {
                $this->ensureConnected();

                $requestId = $this->nextRequestId();
                $authToken = $this->authenticationToken ?? NodeId::numeric(0, 0);
                $request = $this->getEndpointsService->encodeGetEndpointsRequest($requestId, $endpointUrl, $authToken);
                $this->transport->send($request);

                $response = $this->transport->receive();
                $responseBody = $this->unwrapResponse($response);
                $decoder = $this->createDecoder($responseBody);

                return $this->getEndpointsService->decodeGetEndpointsResponse($decoder);
            }),
            $useCache,
        );
    }

    /**
     * Browse references from a single node.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return ReferenceDescription[]
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);
        $paramsSuffix = sprintf('%d:%d:%d', $direction->value, $includeSubtypes ? 1 : 0, $nodeClassMask);

        return $this->cachedFetch(
            $this->buildCacheKey('browse', $nodeId, $paramsSuffix),
            fn() => $this->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
                $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);
                return $this->browseService->decodeBrowseResponse($decoder);
            }),
            $useCache,
        );
    }

    /**
     * Browse references from a single node, returning results with a continuation point for pagination.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return BrowseResultSet
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): BrowseResultSet
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);
        return $this->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
            $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

            return $this->browseService->decodeBrowseResponseWithContinuation($decoder);
        });
    }

    /**
     * Continue a previously started browse operation using a continuation point.
     *
     * @param string $continuationPoint The opaque continuation point from a previous browse.
     * @return BrowseResultSet
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        return $this->executeWithRetry(function () use ($continuationPoint) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->browseService->encodeBrowseNextRequest($requestId, $continuationPoint, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->browseService->decodeBrowseNextResponse($decoder);
        });
    }

    /**
     * Browse all references from a node, automatically following continuation points.
     *
     * @param NodeId|string $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return ReferenceDescription[]
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function browseAll(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
        bool            $useCache = true,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);
        $paramsSuffix = sprintf('%d:%d:%d', $direction->value, $includeSubtypes ? 1 : 0, $nodeClassMask);

        return $this->cachedFetch(
            $this->buildCacheKey('browseAll', $nodeId, $paramsSuffix),
            function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses) {
                $result = $this->browseWithContinuation($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses);
                $allRefs = $result->references;

                while ($result->continuationPoint !== null) {
                    $result = $this->browseNext($result->continuationPoint);
                    array_push($allRefs, ...$result->references);
                }

                return $allRefs;
            },
            $useCache,
        );
    }

    private const MAX_BROWSE_RECURSIVE_DEPTH = 256;

    /**
     * Recursively browse the address space starting from a node, building a tree of BrowseNode objects.
     *
     * @param NodeId|string $nodeId The root node to start browsing from.
     * @param BrowseDirection $direction The browse direction.
     * @param ?int $maxDepth Maximum recursion depth, or null to use the default.
     * @param ?NodeId $referenceTypeId Filter by reference type, or null for all.
     * @param bool $includeSubtypes Whether to include subtypes of the reference type.
     * @param NodeClass[] $nodeClasses Filter by node classes. Empty array means all classes.
     * @return BrowseNode[]
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function browseRecursive(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int            $maxDepth = null,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $resolvedDepth = $maxDepth ?? $this->getDefaultBrowseMaxDepth();
        $effectiveMaxDepth = $resolvedDepth === -1
            ? self::MAX_BROWSE_RECURSIVE_DEPTH
            : min($resolvedDepth, self::MAX_BROWSE_RECURSIVE_DEPTH);

        $visited = [];

        return $this->browseRecursiveInternal($nodeId, $effectiveMaxDepth, 1, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses, $visited);
    }

    /**
     * @param NodeId $nodeId
     * @param int $maxDepth
     * @param int $currentDepth
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
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
        array           $nodeClasses,
        array           &$visited,
    ): array
    {
        $references = $this->browseAll($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses);
        $nodes = [];

        foreach ($references as $ref) {
            $nodeKey = $ref->nodeId->__toString();

            if (isset($visited[$nodeKey])) {
                $nodes[] = new BrowseNode($ref);
                continue;
            }

            $node = new BrowseNode($ref);
            $visited[$nodeKey] = true;

            if ($currentDepth < $maxDepth) {
                $children = $this->browseRecursiveInternal(
                    $ref->nodeId,
                    $maxDepth,
                    $currentDepth + 1,
                    $direction,
                    $referenceTypeId,
                    $includeSubtypes,
                    $nodeClasses,
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
     * Send a browse request and return a BinaryDecoder for the response body.
     *
     * @param NodeId $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
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
        return $this->createDecoder($responseBody);
    }

    /**
     * Convert an array of NodeClass enum values to a bitmask integer.
     *
     * @param NodeClass[] $nodeClasses
     * @return int
     */
    private static function nodeClassesToMask(array $nodeClasses): int
    {
        $mask = 0;
        foreach ($nodeClasses as $nc) {
            $mask |= $nc->value;
        }
        return $mask;
    }
}
