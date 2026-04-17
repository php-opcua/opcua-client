<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\NodeManagement;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

/**
 * Provides OPC UA NodeManagement operations: AddNodes, DeleteNodes, AddReferences, DeleteReferences.
 */
class NodeManagementModule extends ServiceModule
{
    private ?NodeManagementService $service = null;

    public function register(): void
    {
        $this->client->registerMethod('addNodes', $this->addNodes(...));
        $this->client->registerMethod('deleteNodes', $this->deleteNodes(...));
        $this->client->registerMethod('addReferences', $this->addReferences(...));
        $this->client->registerMethod('deleteReferences', $this->deleteReferences(...));
    }

    public function boot(SessionService $session): void
    {
        $this->service = new NodeManagementService($session);
    }

    public function reset(): void
    {
        $this->service = null;
    }

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
        $this->kernel->resolveNodeIdArray($nodesToAdd, 'parentNodeId');
        $this->kernel->resolveNodeIdArray($nodesToAdd, 'referenceTypeId');
        $this->kernel->resolveNodeIdArray($nodesToAdd, 'requestedNewNodeId');
        $this->kernel->resolveNodeIdArray($nodesToAdd, 'typeDefinition');

        return $this->kernel->executeWithRetry(function () use ($nodesToAdd) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->service->encodeAddNodesRequest($requestId, $nodesToAdd, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('AddNodes request: {count} node(s)', $this->kernel->logContext(['count' => count($nodesToAdd)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->service->decodeAddNodesResponse($decoder);
            $this->kernel->log()->debug('AddNodes response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

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
        $this->kernel->resolveNodeIdArray($nodesToDelete);

        return $this->kernel->executeWithRetry(function () use ($nodesToDelete) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->service->encodeDeleteNodesRequest($requestId, $nodesToDelete, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('DeleteNodes request: {count} node(s)', $this->kernel->logContext(['count' => count($nodesToDelete)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->service->decodeDeleteNodesResponse($decoder);
            $this->kernel->log()->debug('DeleteNodes response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

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
        $this->kernel->resolveNodeIdArray($referencesToAdd, 'sourceNodeId');
        $this->kernel->resolveNodeIdArray($referencesToAdd, 'referenceTypeId');
        $this->kernel->resolveNodeIdArray($referencesToAdd, 'targetNodeId');

        return $this->kernel->executeWithRetry(function () use ($referencesToAdd) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->service->encodeAddReferencesRequest($requestId, $referencesToAdd, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('AddReferences request: {count} reference(s)', $this->kernel->logContext(['count' => count($referencesToAdd)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->service->decodeAddReferencesResponse($decoder);
            $this->kernel->log()->debug('AddReferences response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

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
        $this->kernel->resolveNodeIdArray($referencesToDelete, 'sourceNodeId');
        $this->kernel->resolveNodeIdArray($referencesToDelete, 'referenceTypeId');
        $this->kernel->resolveNodeIdArray($referencesToDelete, 'targetNodeId');

        return $this->kernel->executeWithRetry(function () use ($referencesToDelete) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->service->encodeDeleteReferencesRequest($requestId, $referencesToDelete, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('DeleteReferences request: {count} reference(s)', $this->kernel->logContext(['count' => count($referencesToDelete)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->service->decodeDeleteReferencesResponse($decoder);
            $this->kernel->log()->debug('DeleteReferences response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            return $results;
        });
    }
}
