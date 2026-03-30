<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Event\NodeValueRead;
use PhpOpcua\Client\Event\NodeValueWriteFailed;
use PhpOpcua\Client\Event\NodeValueWritten;
use PhpOpcua\Client\Event\WriteTypeDetected;
use PhpOpcua\Client\Event\WriteTypeDetecting;
use PhpOpcua\Client\Exception\WriteTypeDetectionException;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * Provides read, write, and method call operations for OPC UA node attributes.
 */
trait ManagesReadWriteTrait
{
    /**
     * Read a single attribute from a node.
     *
     * When metadata caching is enabled, non-Value attributes are served from cache
     * on subsequent calls. Use `$refresh = true` to bypass the cache and re-read
     * from the server.
     *
     * @param NodeId|string $nodeId The node to read.
     * @param int $attributeId The attribute to read (default 13 = Value).
     * @param bool $refresh Force a server read even if the result is cached.
     * @return DataValue
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     *
     * @see DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $useMetadataCache = $this->readMetadataCache && $attributeId !== AttributeId::Value && ! $refresh;

        if ($useMetadataCache) {
            $cacheKey = $this->buildCacheKey('readMeta:' . $attributeId, $nodeId);
            $this->logger->debug('Reading metadata attribute {attr} for node {nodeId} (cache enabled)', [
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]);

            return $this->cachedFetch($cacheKey, fn () => $this->readFromServer($nodeId, $attributeId), true);
        }

        if ($refresh && $this->readMetadataCache && $attributeId !== AttributeId::Value) {
            $this->logger->debug('Refreshing metadata attribute {attr} for node {nodeId}', [
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]);
            $cacheKey = $this->buildCacheKey('readMeta:' . $attributeId, $nodeId);
            $dataValue = $this->readFromServer($nodeId, $attributeId);

            $this->ensureCacheInitialized();
            if ($this->cache !== null) {
                $this->cache->set($cacheKey, $dataValue);
            }

            return $dataValue;
        }

        return $this->readFromServer($nodeId, $attributeId);
    }

    /**
     * Perform the actual read from the server.
     *
     * @param NodeId $nodeId The node to read.
     * @param int $attributeId The attribute to read.
     * @return DataValue
     */
    private function readFromServer(NodeId $nodeId, int $attributeId): DataValue
    {
        $dataValue = $this->executeWithRetry(function () use ($nodeId, $attributeId) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->readService->encodeReadRequest($requestId, $nodeId, $this->authenticationToken, $attributeId);
            $this->logger->debug('Read request for node {nodeId} (attributeId={attr})', [
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $dataValue = $this->readService->decodeReadResponse($decoder);
            $this->logger->debug('Read response for node {nodeId}: statusCode={status}', [
                'nodeId' => (string) $nodeId,
                'status' => $dataValue->statusCode,
            ]);

            $this->dispatch(fn () => new NodeValueRead($this, $nodeId, $attributeId, $dataValue));

            return $dataValue;
        });

        return $this->applyEnumMapping($nodeId, $dataValue);
    }

    /**
     * Cast the DataValue's raw value to a BackedEnum if the node has an enum mapping.
     *
     * @param NodeId $nodeId The node that was read.
     * @param DataValue $dataValue The raw DataValue from the server.
     * @return DataValue The original DataValue, or a new one with the enum-cast value.
     */
    private function applyEnumMapping(NodeId $nodeId, DataValue $dataValue): DataValue
    {
        $nodeIdStr = (string) $nodeId;
        if (! isset($this->enumMappings[$nodeIdStr])) {
            return $dataValue;
        }

        $rawValue = $dataValue->getValue();
        if (! is_int($rawValue) && ! is_string($rawValue)) {
            return $dataValue;
        }

        $enumClass = $this->enumMappings[$nodeIdStr];

        try {
            $enumValue = $enumClass::from($rawValue);

            return new DataValue(
                new Variant($dataValue->getVariant()->type ?? BuiltinType::Int32, $enumValue),
                $dataValue->statusCode,
                $dataValue->sourceTimestamp,
                $dataValue->serverTimestamp,
            );
        } catch (\ValueError) {
            return $dataValue;
        }
    }

    /**
     * Read multiple attributes from one or more nodes in a single request.
     *
     * Results are automatically batched if the number of items exceeds the effective batch size.
     *
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int}> $readItems Items to read, or null to get a fluent builder.
     * @return ($readItems is null ? \PhpOpcua\Client\Builder\ReadMultiBuilder : DataValue[])
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     */
    public function readMulti(?array $readItems = null): array|\PhpOpcua\Client\Builder\ReadMultiBuilder
    {
        if ($readItems === null) {
            return new \PhpOpcua\Client\Builder\ReadMultiBuilder($this);
        }

        $this->resolveNodeIdArrayParam($readItems);

        $batchSize = $this->getEffectiveReadBatchSize();
        if ($batchSize !== null && count($readItems) > $batchSize) {
            return $this->readMultiBatched($readItems, $batchSize);
        }

        return $this->readMultiRaw($readItems);
    }

    /**
     * Perform a raw multi-read request without batching.
     *
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items Items to read.
     * @return DataValue[]
     */
    private function readMultiRaw(array $items): array
    {
        return $this->executeWithRetry(function () use ($items) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->readService->encodeReadMultiRequest($requestId, $items, $this->authenticationToken);
            $this->logger->debug('ReadMulti request: {count} item(s)', ['count' => count($items)]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->readService->decodeReadMultiResponse($decoder);
            $this->logger->debug('ReadMulti response: {count} value(s)', ['count' => count($results)]);

            return $results;
        });
    }

    /**
     * Perform a batched multi-read request by splitting items into chunks.
     *
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items Items to read.
     * @param int $batchSize Maximum items per batch.
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
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
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
            $this->logger->debug('Write request for node {nodeId} (type={type})', [
                'nodeId' => (string) $nodeId,
                'type' => $type->name,
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->writeService->decodeWriteResponse($decoder);
            $statusCode = $results[0] ?? 0;
            $this->logger->debug('Write response for node {nodeId}: statusCode={status}', [
                'nodeId' => (string) $nodeId,
                'status' => $statusCode,
            ]);

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
     * @return ($writeItems is null ? \PhpOpcua\Client\Builder\WriteMultiBuilder : int[])
     *
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
     * @throws WriteTypeDetectionException If a type cannot be determined for an item.
     */
    public function writeMulti(?array $writeItems = null): array|\PhpOpcua\Client\Builder\WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new \PhpOpcua\Client\Builder\WriteMultiBuilder($this);
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
            $this->logger->debug('WriteMulti request: {count} item(s)', ['count' => count($writeItems)]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $results = $this->writeService->decodeWriteResponse($decoder);
            $this->logger->debug('WriteMulti response: {count} result(s)', ['count' => count($results)]);

            return $results;
        });
    }

    /**
     * Perform a batched multi-write request by splitting items into chunks.
     *
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items Items to write.
     * @param int $batchSize Maximum items per batch.
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
                $this->logger->debug('WriteMulti batch request: {count} item(s)', ['count' => count($writeItems)]);
                $this->transport->send($request);

                $response = $this->transport->receive();
                $responseBody = $this->unwrapResponse($response);
                $decoder = $this->createDecoder($responseBody);

                $batchResult = $this->writeService->decodeWriteResponse($decoder);
                $this->logger->debug('WriteMulti batch response: {count} result(s)', ['count' => count($batchResult)]);

                return $batchResult;
            });
            array_push($results, ...$batchResults);
        }

        return $results;
    }

    /**
     * Prepare write items by resolving types and building DataValue objects.
     *
     * @param array<array{nodeId: NodeId, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items Raw write items.
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
     * @param array<array{nodeId: NodeId, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items Write items to prefetch types for.
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
     * @throws \PhpOpcua\Client\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost during the request.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error response.
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
            $this->logger->debug('Call request: objectId={objectId}, methodId={methodId}, {argCount} argument(s)', [
                'objectId' => (string) $objectId,
                'methodId' => (string) $methodId,
                'argCount' => count($inputArguments),
            ]);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            $result = $this->callService->decodeCallResponse($decoder);
            $this->logger->debug('Call response: statusCode={status}', ['status' => $result->statusCode]);

            return $result;
        });
    }
}
