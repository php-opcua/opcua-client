<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

use DateTimeImmutable;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

/**
 * Provides historical data access operations for reading raw, processed, and at-time node values.
 */
class HistoryModule extends ServiceModule
{
    private ?HistoryReadService $historyReadService = null;

    public function register(): void
    {
        $this->client->registerMethod('historyReadRaw', $this->historyReadRaw(...));
        $this->client->registerMethod('historyReadProcessed', $this->historyReadProcessed(...));
        $this->client->registerMethod('historyReadAtTime', $this->historyReadAtTime(...));
    }

    public function boot(SessionService $session): void
    {
        $this->historyReadService = new HistoryReadService($session);
    }

    public function reset(): void
    {
        $this->historyReadService = null;
    }

    /**
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
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $startTime, $endTime, $numValuesPerNode, $returnBounds) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadRawRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $startTime,
                $endTime,
                $numValuesPerNode,
                $returnBounds,
            );
            $this->kernel->log()->debug('HistoryReadRaw request for node {nodeId}', $this->kernel->logContext(['nodeId' => (string) $nodeId]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->kernel->log()->debug('HistoryReadRaw response for node {nodeId}: {count} value(s)', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]));

            return $results;
        });
    }

    /**
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
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $startTime, $endTime, $processingInterval, $aggregateType) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadProcessedRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $startTime,
                $endTime,
                $processingInterval,
                $aggregateType,
            );
            $this->kernel->log()->debug('HistoryReadProcessed request for node {nodeId} (interval={interval}ms)', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'interval' => $processingInterval,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->kernel->log()->debug('HistoryReadProcessed response for node {nodeId}: {count} value(s)', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]));

            return $results;
        });
    }

    /**
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
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $timestamps) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadAtTimeRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $timestamps,
            );
            $this->kernel->log()->debug('HistoryReadAtTime request for node {nodeId} ({count} timestamp(s))', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($timestamps),
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->historyReadService->decodeHistoryReadResponse($decoder);
            $this->kernel->log()->debug('HistoryReadAtTime response for node {nodeId}: {count} value(s)', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($results),
            ]));

            return $results;
        });
    }
}
