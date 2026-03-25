<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Event\NodeValueRead;
use Gianfriaur\OpcuaPhpClient\Event\NodeValueWriteFailed;
use Gianfriaur\OpcuaPhpClient\Event\NodeValueWritten;
use Gianfriaur\OpcuaPhpClient\Event\WriteTypeDetected;
use Gianfriaur\OpcuaPhpClient\Event\WriteTypeDetecting;
use Gianfriaur\OpcuaPhpClient\Exception\WriteTypeDetectionException;
use Gianfriaur\OpcuaPhpClient\Exception\WriteTypeMismatchException;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

/**
 * Provides read, write, and method call operations for OPC UA node attributes.
 */
trait ManagesReadWriteTrait
{
    private bool $autoDetectWriteType = true;

    /**
     * Enable or disable automatic write type detection.
     *
     * When enabled (default), write operations without an explicit type will read the node
     * first to determine the correct BuiltinType. When a type is provided explicitly,
     * it is validated against the detected type. Detected types are cached via PSR-16.
     *
     * When disabled, an explicit BuiltinType must be passed to every write call.
     *
     * @param bool $enabled Whether to enable auto-detection.
     * @return self
     */
    public function setAutoDetectWriteType(bool $enabled): self
    {
        $this->autoDetectWriteType = $enabled;

        return $this;
    }

    /**
     * Read a single attribute from a node.
     *
     * @param NodeId|string $nodeId The node to read.
     * @param int $attributeId The attribute to read (default 13 = Value).
     * @return DataValue
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     *
     * @see DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value): DataValue
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->executeWithRetry(function () use ($nodeId, $attributeId) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->readService->encodeReadRequest($requestId, $nodeId, $this->authenticationToken, $attributeId);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $dataValue = $this->readService->decodeReadResponse($decoder);

            $this->dispatch(fn () => new NodeValueRead($this, $nodeId, $attributeId, $dataValue));

            return $dataValue;
        });
    }

    /**
     * Read multiple attributes from one or more nodes in a single request.
     *
     * Results are automatically batched if the number of items exceeds the effective batch size.
     *
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int}> $readItems Items to read, or null to get a fluent builder.
     * @return ($readItems is null ? \Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder : DataValue[])
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     */
    public function readMulti(?array $readItems = null): array|\Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder
    {
        if ($readItems === null) {
            return new \Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder($this);
        }

        $this->resolveNodeIdArrayParam($readItems);

        $batchSize = $this->getEffectiveReadBatchSize();
        if ($batchSize !== null && count($readItems) > $batchSize) {
            return $this->readMultiBatched($readItems, $batchSize);
        }

        return $this->readMultiRaw($readItems);
    }

    /**
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @return DataValue[]
     */
    private function readMultiRaw(array $items): array
    {
        return $this->executeWithRetry(function () use ($items) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->readService->encodeReadMultiRequest($requestId, $items, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->readService->decodeReadMultiResponse($decoder);
        });
    }

    /**
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @param int $batchSize
     * @return DataValue[]
     */
    private function readMultiBatched(array $items, int $batchSize): array
    {
        $batches = (int) ceil(count($items) / $batchSize);
        $this->logger->info('Splitting readMulti into {batches} batches of {size}', ['batches' => $batches, 'size' => $batchSize, 'total' => count($items)]);
        $results = [];
        foreach (array_chunk($items, $batchSize) as $batch) {
            $batchResults = $this->readMultiRaw($batch);
            array_push($results, ...$batchResults);
        }

        return $results;
    }

    /**
     * Write a value to a node attribute.
     *
     * When no type is provided and auto-detect is enabled, the client reads the node first
     * to determine the correct BuiltinType (cached via PSR-16). When a type is provided
     * explicitly, it is used directly without any read.
     *
     * @param NodeId|string $nodeId The node to write to.
     * @param mixed $value The value to write.
     * @param ?BuiltinType $type The OPC UA built-in type, or null for auto-detection.
     * @return int The OPC UA status code for the write result.
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If the type cannot be determined.
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $type = $this->resolveWriteType($nodeId, $type);

        return $this->executeWithRetry(function () use ($nodeId, $value, $type) {
            $this->ensureConnected();

            $variant = new Variant($type, $value);
            $dataValue = new DataValue($variant);

            $requestId = $this->nextRequestId();
            $request = $this->writeService->encodeWriteRequest($requestId, $nodeId, $dataValue, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->writeService->decodeWriteResponse($decoder);
            $statusCode = $results[0] ?? 0;

            if (StatusCode::isGood($statusCode)) {
                $this->dispatch(fn () => new NodeValueWritten($this, $nodeId, $value, $type, $statusCode));
            } else {
                $this->dispatch(fn () => new NodeValueWriteFailed($this, $nodeId, $statusCode));
            }

            return $statusCode;
        });
    }

    /**
     * Write multiple values to one or more nodes in a single request.
     *
     * Results are automatically batched if the number of items exceeds the effective batch size.
     * When auto-detect is enabled, items without a type key will have their type resolved
     * automatically via a read (or cache).
     *
     * @param ?array<array{nodeId: NodeId|string, value: mixed, type?: ?BuiltinType, attributeId?: int}> $writeItems Items to write, or null to get a fluent builder.
     * @return ($writeItems is null ? \Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder : int[])
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If a type cannot be determined for an item.
     */
    public function writeMulti(?array $writeItems = null): array|\Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new \Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder($this);
        }

        $this->resolveNodeIdArrayParam($writeItems);

        $batchSize = $this->getEffectiveWriteBatchSize();
        if ($batchSize !== null && count($writeItems) > $batchSize) {
            return $this->writeMultiBatched($writeItems, $batchSize);
        }

        return $this->executeWithRetry(function () use ($writeItems) {
            $this->ensureConnected();

            $writeItems = $this->prepareWriteItems($writeItems);

            $requestId = $this->nextRequestId();
            $request = $this->writeService->encodeWriteMultiRequest($requestId, $writeItems, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->writeService->decodeWriteResponse($decoder);
        });
    }

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items
     * @param int $batchSize
     * @return int[]
     */
    private function writeMultiBatched(array $items, int $batchSize): array
    {
        $results = [];
        foreach (array_chunk($items, $batchSize) as $batch) {
            $batchResults = $this->executeWithRetry(function () use ($batch) {
                $this->ensureConnected();

                $writeItems = $this->prepareWriteItems($batch);

                $requestId = $this->nextRequestId();
                $request = $this->writeService->encodeWriteMultiRequest($requestId, $writeItems, $this->authenticationToken);
                $this->transport->send($request);

                $response = $this->transport->receive();
                $responseBody = $this->unwrapResponse($response);
                $decoder = $this->createDecoder($responseBody);

                return $this->writeService->decodeWriteResponse($decoder);
            });
            array_push($results, ...$batchResults);
        }

        return $results;
    }

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items
     * @return array<array{nodeId: NodeId, dataValue: DataValue, attributeId: int}>
     */
    private function prepareWriteItems(array $items): array
    {
        $this->prefetchWriteTypes($items);

        $writeItems = [];
        foreach ($items as $item) {
            $type = $this->resolveWriteType($item['nodeId'], $item['type'] ?? null);
            $variant = new Variant($type, $item['value']);
            $writeItems[] = [
                'nodeId' => $item['nodeId'],
                'dataValue' => new DataValue($variant),
                'attributeId' => $item['attributeId'] ?? AttributeId::Value,
            ];
        }

        return $writeItems;
    }

    /**
     * Prefetch write types for items that need auto-detection, using a single readMulti.
     *
     * @param array<array{nodeId: NodeId, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items
     * @return void
     */
    private function prefetchWriteTypes(array $items): void
    {
        if (! $this->autoDetectWriteType) {
            return;
        }

        $this->ensureCacheInitialized();

        $uncachedNodes = [];
        $seen = [];
        foreach ($items as $item) {
            $nodeId = $item['nodeId'];
            $key = $nodeId->__toString();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $cacheKey = $this->buildCacheKey('writeType', $nodeId);
            if ($this->cache !== null && $this->cache->get($cacheKey) !== null) {
                continue;
            }

            $uncachedNodes[] = ['nodeId' => $nodeId, 'cacheKey' => $cacheKey];
        }

        if (count($uncachedNodes) <= 1) {
            return;
        }

        $this->logger->info('Prefetching write types for {count} node(s) via readMulti', ['count' => count($uncachedNodes)]);
        $readItems = array_map(fn ($n) => ['nodeId' => $n['nodeId']], $uncachedNodes);
        $dataValues = $this->readMulti($readItems);

        foreach ($uncachedNodes as $i => $node) {
            $variant = $dataValues[$i]->getVariant();
            if ($variant !== null && $this->cache !== null) {
                $this->cache->set($node['cacheKey'], $variant->type);
            }
        }
    }

    /**
     * Resolve the BuiltinType for a write operation.
     *
     * When a type is provided explicitly, it is returned immediately.
     * When no type is provided and auto-detect is enabled, reads the node (with caching).
     *
     * @param NodeId $nodeId The node being written to.
     * @param ?BuiltinType $type The explicit type, or null for auto-detection.
     * @return BuiltinType The resolved type.
     *
     * @throws WriteTypeDetectionException If the type cannot be determined.
     */
    private function resolveWriteType(NodeId $nodeId, ?BuiltinType $type): BuiltinType
    {
        if ($type !== null) {
            $this->logger->debug('Using explicit write type {type} for node {nodeId}', [
                'nodeId' => (string) $nodeId,
                'type' => $type->name,
            ]);

            return $type;
        }

        if (! $this->autoDetectWriteType) {
            $this->logger->warning('Write type auto-detection is disabled and no type provided for node {nodeId}', ['nodeId' => (string) $nodeId]);

            throw new WriteTypeDetectionException(
                "Write type auto-detection is disabled and no explicit type was provided for node {$nodeId}",
            );
        }

        $this->logger->debug('Detecting write type for node {nodeId}', ['nodeId' => (string) $nodeId]);
        $this->dispatch(fn () => new WriteTypeDetecting($this, $nodeId));

        $cacheKey = $this->buildCacheKey('writeType', $nodeId);

        $this->ensureCacheInitialized();
        $fromCache = $this->cache !== null && $this->cache->get($cacheKey) !== null;

        $detectedType = $this->cachedFetch($cacheKey, function () use ($nodeId) {
            $this->logger->debug('Reading node {nodeId} for write type detection', ['nodeId' => (string) $nodeId]);
            $dataValue = $this->read($nodeId);
            $variant = $dataValue->getVariant();

            if ($variant === null) {
                $this->logger->warning('Cannot auto-detect write type for node {nodeId}: no value', ['nodeId' => (string) $nodeId]);

                throw new WriteTypeDetectionException(
                    "Cannot auto-detect write type for node {$nodeId}: node has no value",
                );
            }

            return $variant->type;
        }, true);

        $this->logger->debug('Write type for node {nodeId}: {type} (fromCache={fromCache})', [
            'nodeId' => (string) $nodeId,
            'type' => $detectedType->name,
            'fromCache' => $fromCache ? 'true' : 'false',
        ]);
        $this->dispatch(fn () => new WriteTypeDetected($this, $nodeId, $detectedType, $fromCache));

        return $detectedType;
    }

    /**
     * Call a method on an object node.
     *
     * @param NodeId|string $objectId The object node that owns the method.
     * @param NodeId|string $methodId The method node to invoke.
     * @param Variant[] $inputArguments Input arguments for the method call.
     * @return CallResult
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error response.
     *
     * @see CallResult
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $objectId = $this->resolveNodeIdParam($objectId);
        $methodId = $this->resolveNodeIdParam($methodId);

        return $this->executeWithRetry(function () use ($objectId, $methodId, $inputArguments) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->callService->encodeCallRequest(
                $requestId,
                $objectId,
                $methodId,
                $inputArguments,
                $this->authenticationToken,
            );
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->callService->decodeCallResponse($decoder);
        });
    }
}
