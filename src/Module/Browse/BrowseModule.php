<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Browse;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Event\NodeBrowsed;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\ReferenceDescription;

/**
 * Provides browse, browseAll, browseRecursive, browseWithContinuation, browseNext, and getEndpoints operations.
 */
class BrowseModule extends ServiceModule
{
    private const MAX_BROWSE_RECURSIVE_DEPTH = 256;

    private ?BrowseService $browseService = null;

    private ?GetEndpointsService $getEndpointsService = null;

    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [];
    }

    public function register(): void
    {
        $this->client->registerMethod('browse', $this->browse(...));
        $this->client->registerMethod('browseAll', $this->browseAll(...));
        $this->client->registerMethod('browseRecursive', $this->browseRecursive(...));
        $this->client->registerMethod('browseWithContinuation', $this->browseWithContinuation(...));
        $this->client->registerMethod('browseNext', $this->browseNext(...));
        $this->client->registerMethod('getEndpoints', $this->getEndpoints(...));
    }

    public function boot(SessionService $session): void
    {
        $this->browseService = new BrowseService($session);
        $this->getEndpointsService = new GetEndpointsService($session);
    }

    public function reset(): void
    {
        $this->browseService = null;
        $this->getEndpointsService = null;
    }

    /**
     * Discover endpoints available at the given server URL.
     *
     * @param string $endpointUrl The OPC UA endpoint URL to query.
     * @param bool $useCache Whether to use cached results.
     * @return EndpointDescription[]
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        $cacheKey = $this->kernel->buildSimpleCacheKey('endpoints', md5($endpointUrl));

        return $this->kernel->cachedFetch(
            $cacheKey,
            fn () => $this->kernel->executeWithRetry(function () use ($endpointUrl) {
                $this->kernel->ensureConnected();

                $requestId = $this->kernel->nextRequestId();
                $authToken = $this->kernel->getAuthToken() ?? NodeId::numeric(0, ServiceTypeId::NULL);
                $request = $this->getEndpointsService->encodeGetEndpointsRequest($requestId, $endpointUrl, $authToken);
                $this->kernel->log()->debug('GetEndpoints request for {url}', $this->kernel->logContext(['url' => $endpointUrl]));
                $this->kernel->send($request);

                $response = $this->kernel->receive();
                $responseBody = $this->kernel->unwrapResponse($response);
                $decoder = $this->kernel->createDecoder($responseBody);

                $endpoints = $this->getEndpointsService->decodeGetEndpointsResponse($decoder);
                $this->kernel->log()->debug('GetEndpoints response: {count} endpoint(s)', $this->kernel->logContext(['count' => count($endpoints)]));

                return $endpoints;
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
     * @param bool $useCache Whether to use cached results.
     * @return ReferenceDescription[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function browse(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = [], bool $useCache = true): array
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);
        $paramsSuffix = sprintf('%d:%d:%d', $direction->value, $includeSubtypes ? 1 : 0, $nodeClassMask);

        $results = $this->kernel->cachedFetch(
            $this->kernel->buildCacheKey('browse', $nodeId, $paramsSuffix),
            fn () => $this->kernel->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
                $decoder = $this->getBinaryDecoder($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

                return $this->browseService->decodeBrowseResponse($decoder);
            }),
            $useCache,
        );

        $this->kernel->dispatch(fn () => new NodeBrowsed($this->client, $nodeId, $direction, count($results)));

        return $results;
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
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function browseWithContinuation(NodeId|string $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, array $nodeClasses = []): BrowseResultSet
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask) {
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
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        return $this->kernel->executeWithRetry(function () use ($continuationPoint) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->browseService->encodeBrowseNextRequest($requestId, $continuationPoint, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('BrowseNext request (continuationPoint present)', $this->kernel->logContext());
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $this->kernel->log()->debug('BrowseNext response received', $this->kernel->logContext());
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

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
     * @param bool $useCache Whether to use cached results.
     * @return ReferenceDescription[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function browseAll(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
        bool $useCache = true,
    ): array {
        $nodeId = $this->kernel->resolveNodeId($nodeId);
        $nodeClassMask = self::nodeClassesToMask($nodeClasses);
        $paramsSuffix = sprintf('%d:%d:%d', $direction->value, $includeSubtypes ? 1 : 0, $nodeClassMask);

        return $this->kernel->cachedFetch(
            $this->kernel->buildCacheKey('browseAll', $nodeId, $paramsSuffix),
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
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function browseRecursive(
        NodeId|string $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int $maxDepth = null,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        array $nodeClasses = [],
    ): array {
        $nodeId = $this->kernel->resolveNodeId($nodeId);
        $resolvedDepth = $maxDepth ?? $this->kernel->getDefaultBrowseMaxDepth();
        $effectiveMaxDepth = $resolvedDepth === -1
            ? self::MAX_BROWSE_RECURSIVE_DEPTH
            : min($resolvedDepth, self::MAX_BROWSE_RECURSIVE_DEPTH);

        $visited = [];

        return $this->browseRecursiveInternal($nodeId, $effectiveMaxDepth, 1, $direction, $referenceTypeId, $includeSubtypes, $nodeClasses, $visited);
    }

    /**
     * Send a browse request and return a BinaryDecoder for the response body.
     *
     * @param NodeId $nodeId The node to browse.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type.
     * @param bool $includeSubtypes Whether to include subtypes.
     * @param int $nodeClassMask The node class bitmask.
     * @return BinaryDecoder
     */
    public function getBinaryDecoder(NodeId $nodeId, BrowseDirection $direction, ?NodeId $referenceTypeId, bool $includeSubtypes, int $nodeClassMask): BinaryDecoder
    {
        $this->kernel->ensureConnected();

        $requestId = $this->kernel->nextRequestId();
        $request = $this->browseService->encodeBrowseRequest(
            $requestId,
            $nodeId,
            $this->kernel->getAuthToken(),
            $direction,
            $referenceTypeId,
            $includeSubtypes,
            $nodeClassMask,
        );
        $this->kernel->log()->debug('Browse request for node {nodeId} (direction={direction})', $this->kernel->logContext([
            'nodeId' => (string) $nodeId,
            'direction' => $direction->name,
        ]));
        $this->kernel->send($request);

        $response = $this->kernel->receive();
        $this->kernel->log()->debug('Browse response received for node {nodeId}', $this->kernel->logContext(['nodeId' => (string) $nodeId]));
        $responseBody = $this->kernel->unwrapResponse($response);

        return $this->kernel->createDecoder($responseBody);
    }

    /**
     * Internal recursive browse implementation.
     *
     * @param NodeId $nodeId The current node to browse.
     * @param int $maxDepth The maximum depth.
     * @param int $currentDepth The current recursion depth.
     * @param BrowseDirection $direction The browse direction.
     * @param ?NodeId $referenceTypeId Filter by reference type.
     * @param bool $includeSubtypes Whether to include subtypes.
     * @param NodeClass[] $nodeClasses Filter by node classes.
     * @param array<string, true> $visited Visited node tracking map.
     * @return BrowseNode[]
     */
    private function browseRecursiveInternal(
        NodeId $nodeId,
        int $maxDepth,
        int $currentDepth,
        BrowseDirection $direction,
        ?NodeId $referenceTypeId,
        bool $includeSubtypes,
        array $nodeClasses,
        array &$visited,
    ): array {
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
