<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ReadWrite;

use PhpOpcua\Client\Builder\ReadMultiBuilder;
use PhpOpcua\Client\Builder\WriteMultiBuilder;
use PhpOpcua\Client\Event\NodeValueRead;
use PhpOpcua\Client\Event\NodeValueWriteFailed;
use PhpOpcua\Client\Event\NodeValueWritten;
use PhpOpcua\Client\Event\WriteTypeDetected;
use PhpOpcua\Client\Event\WriteTypeDetecting;
use PhpOpcua\Client\Exception\WriteTypeDetectionException;
use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Protocol\SessionService;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireTypeRegistry;

/**
 * Provides read, write, readMulti, writeMulti, and call operations.
 */
class ReadWriteModule extends ServiceModule
{
    private ?ReadService $readService = null;

    private ?WriteService $writeService = null;

    private ?CallService $callService = null;

    public function register(): void
    {
        $this->client->registerMethod('read', $this->read(...));
        $this->client->registerMethod('readMulti', $this->readMulti(...));
        $this->client->registerMethod('write', $this->write(...));
        $this->client->registerMethod('writeMulti', $this->writeMulti(...));
        $this->client->registerMethod('call', $this->call(...));
    }

    public function boot(SessionService $session): void
    {
        $this->readService = new ReadService($session);
        $this->writeService = new WriteService($session);
        $this->callService = new CallService($session);
    }

    public function reset(): void
    {
        $this->readService = null;
        $this->writeService = null;
        $this->callService = null;
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(CallResult::class);
    }

    /**
     * @param NodeId|string $nodeId
     * @param int $attributeId
     * @param bool $refresh
     * @return DataValue
     */
    public function read(NodeId|string $nodeId, int $attributeId = AttributeId::Value, bool $refresh = false): DataValue
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);

        $useMetadataCache = $this->kernel->isReadMetadataCache() && $attributeId !== AttributeId::Value && ! $refresh;

        if ($useMetadataCache) {
            $cacheKey = $this->kernel->buildCacheKey('readMeta:' . $attributeId, $nodeId);
            $this->kernel->log()->debug('Reading metadata attribute {attr} for node {nodeId} (cache enabled)', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]));

            return $this->kernel->cachedFetch($cacheKey, fn () => $this->readFromServer($nodeId, $attributeId), true);
        }

        if ($refresh && $this->kernel->isReadMetadataCache() && $attributeId !== AttributeId::Value) {
            $this->kernel->log()->debug('Refreshing metadata attribute {attr} for node {nodeId}', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]));
            $cacheKey = $this->kernel->buildCacheKey('readMeta:' . $attributeId, $nodeId);
            $dataValue = $this->readFromServer($nodeId, $attributeId);

            $this->kernel->ensureCacheInitialized();
            $cache = $this->kernel->getCache();
            if ($cache !== null) {
                $cache->set($cacheKey, $dataValue);
            }

            return $dataValue;
        }

        return $this->readFromServer($nodeId, $attributeId);
    }

    /**
     * @param ?array<array{nodeId: NodeId|string, attributeId?: int}> $readItems
     * @return ($readItems is null ? ReadMultiBuilder : DataValue[])
     */
    public function readMulti(?array $readItems = null): array|ReadMultiBuilder
    {
        if ($readItems === null) {
            return new ReadMultiBuilder($this->client);
        }

        $this->kernel->resolveNodeIdArray($readItems);

        $batchSize = $this->kernel->getEffectiveReadBatchSize();
        if ($batchSize !== null && count($readItems) > $batchSize) {
            return $this->readMultiBatched($readItems, $batchSize);
        }

        return $this->readMultiRaw($readItems);
    }

    /**
     * @param NodeId|string $nodeId
     * @param mixed $value
     * @param ?BuiltinType $type
     * @return int
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
    {
        $nodeId = $this->kernel->resolveNodeId($nodeId);
        $type = $this->resolveWriteType($nodeId, $type);

        return $this->kernel->executeWithRetry(function () use ($nodeId, $value, $type) {
            $this->kernel->ensureConnected();

            $variant = new Variant($type, $value);
            $dataValue = new DataValue($variant);

            $requestId = $this->kernel->nextRequestId();
            $request = $this->writeService->encodeWriteRequest($requestId, $nodeId, $dataValue, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('Write request for node {nodeId} (type={type})', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'type' => $type->name,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->writeService->decodeWriteResponse($decoder);
            $statusCode = $results[0] ?? 0;
            $this->kernel->log()->debug('Write response for node {nodeId}: statusCode={status}', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'status' => $statusCode,
            ]));

            if (StatusCode::isGood($statusCode)) {
                $this->kernel->dispatch(fn () => new NodeValueWritten($this->client, $nodeId, $value, $type, $statusCode));
            } else {
                $this->kernel->dispatch(fn () => new NodeValueWriteFailed($this->client, $nodeId, $statusCode));
            }

            return $statusCode;
        });
    }

    /**
     * @param ?array<array{nodeId: NodeId|string, value: mixed, type?: ?BuiltinType, attributeId?: int}> $writeItems
     * @return ($writeItems is null ? WriteMultiBuilder : int[])
     */
    public function writeMulti(?array $writeItems = null): array|WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new WriteMultiBuilder($this->client);
        }

        $this->kernel->resolveNodeIdArray($writeItems);

        $batchSize = $this->kernel->getEffectiveWriteBatchSize();
        if ($batchSize !== null && count($writeItems) > $batchSize) {
            return $this->writeMultiBatched($writeItems, $batchSize);
        }

        return $this->kernel->executeWithRetry(function () use ($writeItems) {
            $this->kernel->ensureConnected();

            $writeItems = $this->prepareWriteItems($writeItems);

            $requestId = $this->kernel->nextRequestId();
            $request = $this->writeService->encodeWriteMultiRequest($requestId, $writeItems, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('WriteMulti request: {count} item(s)', $this->kernel->logContext(['count' => count($writeItems)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->writeService->decodeWriteResponse($decoder);
            $this->kernel->log()->debug('WriteMulti response: {count} result(s)', $this->kernel->logContext(['count' => count($results)]));

            return $results;
        });
    }

    /**
     * @param NodeId|string $objectId
     * @param NodeId|string $methodId
     * @param Variant[] $inputArguments
     * @return CallResult
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $objectId = $this->kernel->resolveNodeId($objectId);
        $methodId = $this->kernel->resolveNodeId($methodId);

        return $this->kernel->executeWithRetry(function () use ($objectId, $methodId, $inputArguments) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->callService->encodeCallRequest(
                $requestId,
                $objectId,
                $methodId,
                $inputArguments,
                $this->kernel->getAuthToken(),
            );
            $this->kernel->log()->debug('Call request: objectId={objectId}, methodId={methodId}, {argCount} argument(s)', $this->kernel->logContext([
                'objectId' => (string) $objectId,
                'methodId' => (string) $methodId,
                'argCount' => count($inputArguments),
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $result = $this->callService->decodeCallResponse($decoder);
            $this->kernel->log()->debug('Call response: statusCode={status}', $this->kernel->logContext(['status' => $result->statusCode]));

            return $result;
        });
    }

    /**
     * Perform a raw multi-read request without batching.
     * Public so it can be used by BatchingModule for server limits discovery.
     *
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @return DataValue[]
     */
    public function readMultiRaw(array $items): array
    {
        return $this->kernel->executeWithRetry(function () use ($items) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->readService->encodeReadMultiRequest($requestId, $items, $this->kernel->getAuthToken());
            $this->kernel->log()->debug('ReadMulti request: {count} item(s)', $this->kernel->logContext(['count' => count($items)]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $results = $this->readService->decodeReadMultiResponse($decoder);
            $this->kernel->log()->debug('ReadMulti response: {count} value(s)', $this->kernel->logContext(['count' => count($results)]));

            return $results;
        });
    }

    private function readFromServer(NodeId $nodeId, int $attributeId): DataValue
    {
        $dataValue = $this->kernel->executeWithRetry(function () use ($nodeId, $attributeId) {
            $this->kernel->ensureConnected();

            $requestId = $this->kernel->nextRequestId();
            $request = $this->readService->encodeReadRequest($requestId, $nodeId, $this->kernel->getAuthToken(), $attributeId);
            $this->kernel->log()->debug('Read request for node {nodeId} (attributeId={attr})', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'attr' => $attributeId,
            ]));
            $this->kernel->send($request);

            $response = $this->kernel->receive();
            $responseBody = $this->kernel->unwrapResponse($response);
            $decoder = $this->kernel->createDecoder($responseBody);

            $dataValue = $this->readService->decodeReadResponse($decoder);
            $this->kernel->log()->debug('Read response for node {nodeId}: statusCode={status}', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'status' => $dataValue->statusCode,
            ]));

            $this->kernel->dispatch(fn () => new NodeValueRead($this->client, $nodeId, $attributeId, $dataValue));

            return $dataValue;
        });

        return $this->applyEnumMapping($nodeId, $dataValue);
    }

    private function applyEnumMapping(NodeId $nodeId, DataValue $dataValue): DataValue
    {
        $enumMappings = $this->kernel->getEnumMappings();
        $nodeIdStr = (string) $nodeId;
        if (! isset($enumMappings[$nodeIdStr])) {
            return $dataValue;
        }

        $rawValue = $dataValue->getValue();
        if (! is_int($rawValue) && ! is_string($rawValue)) {
            return $dataValue;
        }

        $enumClass = $enumMappings[$nodeIdStr];

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
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @param int $batchSize
     * @return DataValue[]
     */
    private function readMultiBatched(array $items, int $batchSize): array
    {
        $batches = (int) ceil(count($items) / $batchSize);
        $this->kernel->log()->info('Splitting readMulti into {batches} batches of {size}', $this->kernel->logContext(['batches' => $batches, 'size' => $batchSize, 'total' => count($items)]));
        $results = [];
        foreach (array_chunk($items, $batchSize) as $batch) {
            $batchResults = $this->readMultiRaw($batch);
            array_push($results, ...$batchResults);
        }

        return $results;
    }

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type?: ?BuiltinType, attributeId?: int}> $items
     * @param int $batchSize
     * @return int[]
     */
    private function writeMultiBatched(array $items, int $batchSize): array
    {
        $results = [];
        foreach (array_chunk($items, $batchSize) as $batch) {
            $batchResults = $this->kernel->executeWithRetry(function () use ($batch) {
                $this->kernel->ensureConnected();

                $writeItems = $this->prepareWriteItems($batch);

                $requestId = $this->kernel->nextRequestId();
                $request = $this->writeService->encodeWriteMultiRequest($requestId, $writeItems, $this->kernel->getAuthToken());
                $this->kernel->log()->debug('WriteMulti batch request: {count} item(s)', $this->kernel->logContext(['count' => count($writeItems)]));
                $this->kernel->send($request);

                $response = $this->kernel->receive();
                $responseBody = $this->kernel->unwrapResponse($response);
                $decoder = $this->kernel->createDecoder($responseBody);

                $batchResult = $this->writeService->decodeWriteResponse($decoder);
                $this->kernel->log()->debug('WriteMulti batch response: {count} result(s)', $this->kernel->logContext(['count' => count($batchResult)]));

                return $batchResult;
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

    private function prefetchWriteTypes(array $items): void
    {
        if (! $this->kernel->isAutoDetectWriteType()) {
            return;
        }

        $this->kernel->ensureCacheInitialized();
        $cache = $this->kernel->getCache();

        $uncachedNodes = [];
        $seen = [];
        foreach ($items as $item) {
            $nodeId = $item['nodeId'];
            $key = $nodeId->__toString();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $cacheKey = $this->kernel->buildCacheKey('writeType', $nodeId);
            if ($cache !== null && $cache->get($cacheKey) !== null) {
                continue;
            }

            $uncachedNodes[] = ['nodeId' => $nodeId, 'cacheKey' => $cacheKey];
        }

        if (count($uncachedNodes) <= 1) {
            return;
        }

        $this->kernel->log()->info('Prefetching write types for {count} node(s) via readMulti', $this->kernel->logContext(['count' => count($uncachedNodes)]));
        $readItems = array_map(fn ($n) => ['nodeId' => $n['nodeId']], $uncachedNodes);
        $dataValues = $this->readMulti($readItems);

        foreach ($uncachedNodes as $i => $node) {
            $variant = $dataValues[$i]->getVariant();
            if ($variant !== null && $cache !== null) {
                $cache->set($node['cacheKey'], $variant->type);
            }
        }
    }

    private function resolveWriteType(NodeId $nodeId, ?BuiltinType $type): BuiltinType
    {
        if ($type !== null) {
            $this->kernel->log()->debug('Using explicit write type {type} for node {nodeId}', $this->kernel->logContext([
                'nodeId' => (string) $nodeId,
                'type' => $type->name,
            ]));

            return $type;
        }

        if (! $this->kernel->isAutoDetectWriteType()) {
            $this->kernel->log()->warning('Write type auto-detection is disabled and no type provided for node {nodeId}', $this->kernel->logContext(['nodeId' => (string) $nodeId]));

            throw new WriteTypeDetectionException(
                "Write type auto-detection is disabled and no explicit type was provided for node {$nodeId}",
            );
        }

        $this->kernel->log()->debug('Detecting write type for node {nodeId}', $this->kernel->logContext(['nodeId' => (string) $nodeId]));
        $this->kernel->dispatch(fn () => new WriteTypeDetecting($this->client, $nodeId));

        $cacheKey = $this->kernel->buildCacheKey('writeType', $nodeId);

        $this->kernel->ensureCacheInitialized();
        $cache = $this->kernel->getCache();
        $fromCache = $cache !== null && $cache->get($cacheKey) !== null;

        $detectedType = $this->kernel->cachedFetch($cacheKey, function () use ($nodeId) {
            $this->kernel->log()->debug('Reading node {nodeId} for write type detection', $this->kernel->logContext(['nodeId' => (string) $nodeId]));
            $dataValue = $this->read($nodeId);
            $variant = $dataValue->getVariant();

            if ($variant === null) {
                $this->kernel->log()->warning('Cannot auto-detect write type for node {nodeId}: no value', $this->kernel->logContext(['nodeId' => (string) $nodeId]));

                throw new WriteTypeDetectionException(
                    "Cannot auto-detect write type for node {$nodeId}: node has no value",
                );
            }

            return $variant->type;
        }, true);

        $this->kernel->log()->debug('Write type for node {nodeId}: {type} (fromCache={fromCache})', $this->kernel->logContext([
            'nodeId' => (string) $nodeId,
            'type' => $detectedType->name,
            'fromCache' => $fromCache ? 'true' : 'false',
        ]));
        $this->kernel->dispatch(fn () => new WriteTypeDetected($this->client, $nodeId, $detectedType, $fromCache));

        return $detectedType;
    }
}
