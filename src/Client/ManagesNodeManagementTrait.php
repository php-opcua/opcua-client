<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Types\AddNodesResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

/**
 * Provides OPC UA NodeManagement operations: AddNodes, DeleteNodes, AddReferences, DeleteReferences.
 */
trait ManagesNodeManagementTrait
{
    /**
     * Add one or more nodes to the server's address space.
     *
     * @param array<array{
     *     parentNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     requestedNewNodeId: NodeId|string,
     *     browseName: QualifiedName,
     *     nodeClass: NodeClass,
     *     typeDefinition: NodeId|string,
     *     displayName?: ?string,
     *     description?: ?string,
     *     writeMask?: int,
     *     userWriteMask?: int,
     *     value?: mixed,
     *     dataType?: ?NodeId,
     *     valueRank?: int,
     *     arrayDimensions?: int[],
     *     accessLevel?: int,
     *     userAccessLevel?: int,
     *     minimumSamplingInterval?: float,
     *     historizing?: bool,
     *     executable?: bool,
     *     userExecutable?: bool,
     *     isAbstract?: bool,
     *     symmetric?: bool,
     *     inverseName?: ?string,
     *     containsNoLoops?: bool,
     *     eventNotifier?: int,
     * }> $nodesToAdd
     * @return AddNodesResult[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see AddNodesResult
     */
    public function addNodes(array $nodesToAdd): array
    {
        $this->resolveNodeIdArrayParam($nodesToAdd, 'parentNodeId');
        $this->resolveNodeIdArrayParam($nodesToAdd, 'referenceTypeId');
        $this->resolveNodeIdArrayParam($nodesToAdd, 'requestedNewNodeId');
        $this->resolveNodeIdArrayParam($nodesToAdd, 'typeDefinition');

        return $this->executeWithRetry(function () use ($nodesToAdd) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->nodeManagementService->encodeAddNodesRequest($requestId, $nodesToAdd, $this->authenticationToken);
            $this->logger->debug('AddNodes request: {count} node(s)', $this->logContext(['count' => count($nodesToAdd)]));
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->nodeManagementService->decodeAddNodesResponse($decoder);
            $this->logger->debug('AddNodes response: {count} result(s)', $this->logContext(['count' => count($results)]));

            return $results;
        });
    }

    /**
     * Delete one or more nodes from the server's address space.
     *
     * @param array<array{nodeId: NodeId|string, deleteTargetReferences?: bool}> $nodesToDelete
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteNodes(array $nodesToDelete): array
    {
        $this->resolveNodeIdArrayParam($nodesToDelete);

        return $this->executeWithRetry(function () use ($nodesToDelete) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->nodeManagementService->encodeDeleteNodesRequest($requestId, $nodesToDelete, $this->authenticationToken);
            $this->logger->debug('DeleteNodes request: {count} node(s)', $this->logContext(['count' => count($nodesToDelete)]));
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->nodeManagementService->decodeDeleteNodesResponse($decoder);
            $this->logger->debug('DeleteNodes response: {count} result(s)', $this->logContext(['count' => count($results)]));

            return $results;
        });
    }

    /**
     * Add one or more references between nodes.
     *
     * @param array<array{
     *     sourceNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     isForward: bool,
     *     targetNodeId: NodeId|string,
     *     targetNodeClass: NodeClass,
     *     targetServerUri?: ?string,
     * }> $referencesToAdd
     * @return int[] OPC UA status codes for each addition.
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function addReferences(array $referencesToAdd): array
    {
        $this->resolveNodeIdArrayParam($referencesToAdd, 'sourceNodeId');
        $this->resolveNodeIdArrayParam($referencesToAdd, 'referenceTypeId');
        $this->resolveNodeIdArrayParam($referencesToAdd, 'targetNodeId');

        return $this->executeWithRetry(function () use ($referencesToAdd) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->nodeManagementService->encodeAddReferencesRequest($requestId, $referencesToAdd, $this->authenticationToken);
            $this->logger->debug('AddReferences request: {count} reference(s)', $this->logContext(['count' => count($referencesToAdd)]));
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->nodeManagementService->decodeAddReferencesResponse($decoder);
            $this->logger->debug('AddReferences response: {count} result(s)', $this->logContext(['count' => count($results)]));

            return $results;
        });
    }

    /**
     * Delete one or more references between nodes.
     *
     * @param array<array{
     *     sourceNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     isForward: bool,
     *     targetNodeId: NodeId|string,
     *     deleteBidirectional?: bool,
     * }> $referencesToDelete
     * @return int[] OPC UA status codes for each deletion.
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function deleteReferences(array $referencesToDelete): array
    {
        $this->resolveNodeIdArrayParam($referencesToDelete, 'sourceNodeId');
        $this->resolveNodeIdArrayParam($referencesToDelete, 'referenceTypeId');
        $this->resolveNodeIdArrayParam($referencesToDelete, 'targetNodeId');

        return $this->executeWithRetry(function () use ($referencesToDelete) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->nodeManagementService->encodeDeleteReferencesRequest($requestId, $referencesToDelete, $this->authenticationToken);
            $this->logger->debug('DeleteReferences request: {count} reference(s)', $this->logContext(['count' => count($referencesToDelete)]));
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->nodeManagementService->decodeDeleteReferencesResponse($decoder);
            $this->logger->debug('DeleteReferences response: {count} result(s)', $this->logContext(['count' => count($results)]));

            return $results;
        });
    }
}
