<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

trait ManagesHistoryTrait
{
    /**
     * @param NodeId $nodeId
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     * @return DataValue[]
     */
    public function historyReadRaw(
        NodeId             $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int                $numValuesPerNode = 0,
        bool               $returnBounds = false,
    ): array {
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
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->historyReadService->decodeHistoryReadResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     * @return DataValue[]
     */
    public function historyReadProcessed(
        NodeId $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array {
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
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->historyReadService->decodeHistoryReadResponse($decoder);
        });
    }

    /**
     * @param NodeId $nodeId
     * @param DateTimeImmutable[] $timestamps
     * @return DataValue[]
     */
    public function historyReadAtTime(
        NodeId $nodeId,
        array $timestamps,
    ): array {
        return $this->executeWithRetry(function () use ($nodeId, $timestamps) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->historyReadService->encodeHistoryReadAtTimeRequest(
                $requestId,
                $this->authenticationToken,
                $nodeId,
                $timestamps,
            );
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->historyReadService->decodeHistoryReadResponse($decoder);
        });
    }
}
