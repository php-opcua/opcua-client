<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

use DateTimeImmutable;
use PhpOpcua\Client\Event\HistoryDataDeleted;
use PhpOpcua\Client\Event\HistoryDataUpdated;
use PhpOpcua\Client\Event\HistoryEventDeleted;
use PhpOpcua\Client\Event\HistoryEventUpdated;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Provides historical data access operations: read (raw / processed / at-time)
 * and update (insert / replace / update / delete for data and events).
 */
class HistoryModule extends ServiceModule
{
    private ?HistoryReadService $historyReadService = null;

    private ?HistoryUpdateService $historyUpdateService = null;

    public function register(): void
    {
        $this->client->registerMethod('historyReadRaw', $this->historyReadRaw(...));
        $this->client->registerMethod('historyReadProcessed', $this->historyReadProcessed(...));
        $this->client->registerMethod('historyReadAtTime', $this->historyReadAtTime(...));
        $this->client->registerMethod('historyInsertData', $this->historyInsertData(...));
        $this->client->registerMethod('historyReplaceData', $this->historyReplaceData(...));
        $this->client->registerMethod('historyUpdateData', $this->historyUpdateData(...));
        $this->client->registerMethod('historyDeleteRawModified', $this->historyDeleteRawModified(...));
        $this->client->registerMethod('historyDeleteAtTime', $this->historyDeleteAtTime(...));
        $this->client->registerMethod('historyInsertEvent', $this->historyInsertEvent(...));
        $this->client->registerMethod('historyReplaceEvent', $this->historyReplaceEvent(...));
        $this->client->registerMethod('historyUpdateEvent', $this->historyUpdateEvent(...));
        $this->client->registerMethod('historyDeleteEvent', $this->historyDeleteEvent(...));
    }

    public function boot(SessionService $session): void
    {
        $this->historyReadService = new HistoryReadService($session);
        $this->historyUpdateService = new HistoryUpdateService($session);
    }

    public function reset(): void
    {
        $this->historyReadService = null;
        $this->historyUpdateService = null;
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(HistoryUpdateResult::class);
        $registry->registerEnum(PerformUpdateType::class);
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

    /**
     * @param NodeId|string $nodeId
     * @param DataValue[] $values
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyInsertData(NodeId|string $nodeId, array $values): array
    {
        return $this->updateData($nodeId, PerformUpdateType::Insert, $values);
    }

    /**
     * @param NodeId|string $nodeId
     * @param DataValue[] $values
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyReplaceData(NodeId|string $nodeId, array $values): array
    {
        return $this->updateData($nodeId, PerformUpdateType::Replace, $values);
    }

    /**
     * @param NodeId|string $nodeId
     * @param DataValue[] $values
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyUpdateData(NodeId|string $nodeId, array $values): array
    {
        return $this->updateData($nodeId, PerformUpdateType::Update, $values);
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param bool $isDeleteModified
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyDeleteRawModified(
        NodeId|string $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        bool $isDeleteModified = false,
    ): int {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $startTime, $endTime, $isDeleteModified) {
            $this->kernel->ensureConnected();
            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyUpdateService->encodeDeleteRawModifiedRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $isDeleteModified,
                $startTime,
                $endTime,
            );
            $this->kernel->log()->debug('HistoryDeleteRawModified for node {nodeId}', $this->kernel->logContext(['nodeId' => (string) $nodeId]));
            $this->kernel->send($request);

            $results = $this->decodeUpdateResponse();
            $statusCode = $results[0]->statusCode ?? 0;

            $this->kernel->dispatch(fn () => new HistoryDataDeleted(
                $this->client,
                $nodeId,
                'rawModified',
                $statusCode,
                [],
            ));

            return $statusCode;
        });
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable[] $timestamps
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyDeleteAtTime(NodeId|string $nodeId, array $timestamps): array
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $timestamps) {
            $this->kernel->ensureConnected();
            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyUpdateService->encodeDeleteAtTimeRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $timestamps,
            );
            $this->kernel->log()->debug('HistoryDeleteAtTime for node {nodeId} ({count} timestamp(s))', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($timestamps),
            ]));
            $this->kernel->send($request);

            $results = $this->decodeUpdateResponse();
            $operationResults = $results[0]->operationResults ?? [];

            $this->kernel->dispatch(fn () => new HistoryDataDeleted(
                $this->client,
                $nodeId,
                'atTime',
                0,
                $operationResults,
            ));

            return $operationResults;
        });
    }

    /**
     * @param NodeId|string $nodeId
     * @param string[] $selectFields
     * @param array<int, \PhpOpcua\Client\Types\Variant[]> $eventData
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyInsertEvent(NodeId|string $nodeId, array $selectFields, array $eventData): array
    {
        return $this->updateEvent($nodeId, PerformUpdateType::Insert, $selectFields, $eventData);
    }

    /**
     * @param NodeId|string $nodeId
     * @param string[] $selectFields
     * @param array<int, \PhpOpcua\Client\Types\Variant[]> $eventData
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyReplaceEvent(NodeId|string $nodeId, array $selectFields, array $eventData): array
    {
        return $this->updateEvent($nodeId, PerformUpdateType::Replace, $selectFields, $eventData);
    }

    /**
     * @param NodeId|string $nodeId
     * @param string[] $selectFields
     * @param array<int, \PhpOpcua\Client\Types\Variant[]> $eventData
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyUpdateEvent(NodeId|string $nodeId, array $selectFields, array $eventData): array
    {
        return $this->updateEvent($nodeId, PerformUpdateType::Update, $selectFields, $eventData);
    }

    /**
     * @param NodeId|string $nodeId
     * @param string[] $eventIds
     * @return int[]
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException
     * @throws \PhpOpcua\Client\Exception\ConnectionException
     * @throws \PhpOpcua\Client\Exception\ServiceException
     */
    public function historyDeleteEvent(NodeId|string $nodeId, array $eventIds): array
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $eventIds) {
            $this->kernel->ensureConnected();
            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyUpdateService->encodeDeleteEventRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $eventIds,
            );
            $this->kernel->log()->debug('HistoryDeleteEvent for node {nodeId} ({count} event(s))', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'count' => count($eventIds),
            ]));
            $this->kernel->send($request);

            $results = $this->decodeUpdateResponse();
            $operationResults = $results[0]->operationResults ?? [];

            $this->kernel->dispatch(fn () => new HistoryEventDeleted(
                $this->client,
                $nodeId,
                count($eventIds),
                $operationResults,
            ));

            return $operationResults;
        });
    }

    /**
     * @param DataValue[] $values
     * @return int[]
     */
    private function updateData(NodeId|string $nodeId, PerformUpdateType $perform, array $values): array
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $perform, $values) {
            $this->kernel->ensureConnected();
            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyUpdateService->encodeUpdateDataRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $perform,
                $values,
            );
            $this->kernel->log()->debug('HistoryUpdateData[{op}] for node {nodeId} ({count} value(s))', $this->kernel->logContext([
                'op' => $perform->name,
                'nodeId' => (string) $nodeId,
                'count' => count($values),
            ]));
            $this->kernel->send($request);

            $results = $this->decodeUpdateResponse();
            $operationResults = $results[0]->operationResults ?? [];

            $this->kernel->dispatch(fn () => new HistoryDataUpdated(
                $this->client,
                $nodeId,
                $perform,
                count($values),
                $operationResults,
            ));

            return $operationResults;
        });
    }

    /**
     * @param string[] $selectFields
     * @param array<int, \PhpOpcua\Client\Types\Variant[]> $eventData
     * @return int[]
     */
    private function updateEvent(
        NodeId|string $nodeId,
        PerformUpdateType $perform,
        array $selectFields,
        array $eventData,
    ): array {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $perform, $selectFields, $eventData) {
            $this->kernel->ensureConnected();
            $requestId = $this->kernel->nextRequestId();
            $request = $this->historyUpdateService->encodeUpdateEventRequest(
                $requestId,
                $this->kernel->getAuthToken(),
                $nodeId,
                $perform,
                $selectFields,
                $eventData,
            );
            $this->kernel->log()->debug('HistoryUpdateEvent[{op}] for node {nodeId} ({count} event(s))', $this->kernel->logContext([
                'op' => $perform->name,
                'nodeId' => (string) $nodeId,
                'count' => count($eventData),
            ]));
            $this->kernel->send($request);

            $results = $this->decodeUpdateResponse();
            $operationResults = $results[0]->operationResults ?? [];

            $this->kernel->dispatch(fn () => new HistoryEventUpdated(
                $this->client,
                $nodeId,
                $perform,
                count($eventData),
                $operationResults,
            ));

            return $operationResults;
        });
    }

    /**
     * @return HistoryUpdateResult[]
     */
    private function decodeUpdateResponse(): array
    {
        $response = $this->kernel->receive();
        $responseBody = $this->kernel->unwrapResponse($response);
        $decoder = $this->kernel->createDecoder($responseBody);

        return $this->historyUpdateService->decodeHistoryUpdateResponse($decoder);
    }
}
