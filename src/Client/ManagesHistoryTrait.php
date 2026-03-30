<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use DateTimeImmutable;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

/**
 * Provides historical data access operations for reading raw, processed, and at-time node values.
 */
trait ManagesHistoryTrait
{
    /**
     * Read raw historical data for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param ?DateTimeImmutable $startTime Start of the time range, or null for open-ended.
     * @param ?DateTimeImmutable $endTime End of the time range, or null for open-ended.
     * @param int $numValuesPerNode Maximum values to return (0 = server default).
     * @param bool $returnBounds Whether to include bounding values.
     * @return DataValue[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function historyReadRaw(
        NodeId|string $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
    ): array {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->executeWithRetry(function () use ($nodeId, $startTime, $endTime, $numValuesPerNode, $returnBounds) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadRawRequest(
                $requestId,
                $this->authenticationToken,
                $nodeId,
                $startTime,
                $endTime,
                $numValuesPerNode,
                $returnBounds,
            );
            $this->logger->debug('HistoryReadRaw request for node {nodeId}', ['nodeId' => (string) $nodeId]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->logger->debug('HistoryReadRaw response for node {nodeId}: {count} value(s)', [
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]);

            return $results;
        });
    }

    /**
     * Read processed (aggregated) historical data for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param DateTimeImmutable $startTime Start of the time range.
     * @param DateTimeImmutable $endTime End of the time range.
     * @param float $processingInterval Aggregation interval in milliseconds.
     * @param NodeId $aggregateType The aggregate function NodeId (e.g. Average, Count).
     * @return DataValue[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function historyReadProcessed(
        NodeId|string $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->executeWithRetry(function () use ($nodeId, $startTime, $endTime, $processingInterval, $aggregateType) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadProcessedRequest(
                $requestId,
                $this->authenticationToken,
                $nodeId,
                $startTime,
                $endTime,
                $processingInterval,
                $aggregateType,
            );
            $this->logger->debug('HistoryReadProcessed request for node {nodeId} (interval={interval}ms)', [
                'nodeId' => (string) $nodeId,
                'interval' => $processingInterval,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->logger->debug('HistoryReadProcessed response for node {nodeId}: {count} value(s)', [
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]);

            return $results;
        });
    }

    /**
     * Read historical data at specific timestamps for a node.
     *
     * @param NodeId|string $nodeId The node to read history from.
     * @param DateTimeImmutable[] $timestamps The exact timestamps to retrieve values for.
     * @return DataValue[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function historyReadAtTime(
        NodeId|string $nodeId,
        array $timestamps,
    ): array {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->executeWithRetry(function () use ($nodeId, $timestamps) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadAtTimeRequest(
                $requestId,
                $this->authenticationToken,
                $nodeId,
                $timestamps,
            );
            $this->logger->debug('HistoryReadAtTime request for node {nodeId} ({count} timestamp(s))', [
                'nodeId' => (string) $nodeId,
                'count' => count($timestamps),
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->logger->debug('HistoryReadAtTime response for node {nodeId}: {count} value(s)', [
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]);

            return $results;
        });
    }
}
