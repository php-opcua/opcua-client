<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

trait ManagesReadWriteTrait
{
    /**
     * @param NodeId $nodeId
     * @param int $attributeId
     * @return DataValue
     */
    public function read(NodeId $nodeId, int $attributeId = 13): DataValue
    {
        return $this->executeWithRetry(function () use ($nodeId, $attributeId) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->readService->encodeReadRequest($requestId, $nodeId, $this->authenticationToken, $attributeId);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->readService->decodeReadResponse($decoder);
        });
    }

    /**
     * @param array<array{nodeId: NodeId, attributeId?: int}> $items
     * @return DataValue[]
     */
    public function readMulti(array $items): array
    {
        $batchSize = $this->getEffectiveReadBatchSize();
        if ($batchSize !== null && count($items) > $batchSize) {
            return $this->readMultiBatched($items, $batchSize);
        }

        return $this->readMultiRaw($items);
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
        $results = [];
        foreach (array_chunk($items, $batchSize) as $batch) {
            $batchResults = $this->readMultiRaw($batch);
            array_push($results, ...$batchResults);
        }

        return $results;
    }

    /**
     * @param NodeId $nodeId
     * @param mixed $value
     * @param BuiltinType $type
     * @return int
     */
    public function write(NodeId $nodeId, mixed $value, BuiltinType $type): int
    {
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

            return $results[0] ?? 0;
        });
    }

    /**
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items
     * @return int[]
     */
    public function writeMulti(array $items): array
    {
        $batchSize = $this->getEffectiveWriteBatchSize();
        if ($batchSize !== null && count($items) > $batchSize) {
            return $this->writeMultiBatched($items, $batchSize);
        }

        return $this->executeWithRetry(function () use ($items) {
            $this->ensureConnected();

            $writeItems = $this->prepareWriteItems($items);

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
     * @param array<array{nodeId: NodeId, value: mixed, type: BuiltinType, attributeId?: int}> $items
     * @return array<array{nodeId: NodeId, dataValue: DataValue, attributeId: int}>
     */
    private function prepareWriteItems(array $items): array
    {
        $writeItems = [];
        foreach ($items as $item) {
            $variant = new Variant($item['type'], $item['value']);
            $writeItems[] = [
                'nodeId' => $item['nodeId'],
                'dataValue' => new DataValue($variant),
                'attributeId' => $item['attributeId'] ?? 13,
            ];
        }

        return $writeItems;
    }

    /**
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @return array{statusCode: int, inputArgumentResults: int[], outputArguments: Variant[]}
     */
    public function call(NodeId $objectId, NodeId $methodId, array $inputArguments = []): array
    {
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
