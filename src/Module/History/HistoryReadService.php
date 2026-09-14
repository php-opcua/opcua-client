<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

use DateTimeImmutable;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

class HistoryReadService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     */
    public function encodeHistoryReadRawRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
        ?string $continuationPoint = null,
        bool $releaseContinuationPoints = false,
    ): string {
        $detailsBody = $this->buildReadRawModifiedDetailsBody($startTime, $endTime, $numValuesPerNode, $returnBounds);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 649, $detailsBody, $continuationPoint, $releaseContinuationPoints);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     */
    public function encodeHistoryReadProcessedRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
        ?string $continuationPoint = null,
        bool $releaseContinuationPoints = false,
    ): string {
        $detailsBody = $this->buildReadProcessedDetailsBody($startTime, $endTime, $processingInterval, $aggregateType);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 652, $detailsBody, $continuationPoint, $releaseContinuationPoints);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param DateTimeImmutable[] $timestamps
     */
    public function encodeHistoryReadAtTimeRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        array $timestamps,
        ?string $continuationPoint = null,
        bool $releaseContinuationPoints = false,
    ): string {
        $detailsBody = $this->buildReadAtTimeDetailsBody($timestamps);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 655, $detailsBody, $continuationPoint, $releaseContinuationPoints);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId[] $nodeIds
     * @param int $detailsTypeId
     * @param string $detailsBody
     */
    private function encodeHistoryReadRequest(
        int $requestId,
        NodeId $authToken,
        array $nodeIds,
        int $detailsTypeId,
        string $detailsBody,
        ?string $continuationPoint = null,
        bool $releaseContinuationPoints = false,
    ): string {
        $body = new BinaryEncoder();
        $this->writeHistoryReadInnerBody($body, $requestId, $authToken, $nodeIds, $detailsTypeId, $detailsBody, $continuationPoint, $releaseContinuationPoints);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return DataValue[]
     */
    public function decodeHistoryReadResponse(BinaryDecoder $decoder): array
    {
        return $this->decodeHistoryReadResponseWithContinuation($decoder)['values'];
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{values: DataValue[], continuationPoint: ?string}
     *
     * @throws ServiceException If the HistoryRead result carries a Bad status code.
     */
    public function decodeHistoryReadResponseWithContinuation(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $allValues = [];
        $continuationPoint = null;

        for ($i = 0; $i < $resultCount; $i++) {
            $statusCode = $decoder->readUInt32();
            if (StatusCode::isBad($statusCode)) {
                throw new ServiceException('HistoryRead failed: ' . StatusCode::getName($statusCode), $statusCode);
            }

            $continuationPoint = $decoder->readByteString();

            $typeId = $decoder->readNodeId();
            $encoding = $decoder->readByte();

            if ($encoding === 0x01) {
                $bodyLength = $decoder->readInt32();
                $bodyStart = $decoder->getOffset();

                $histTypeId = $typeId->getIdentifier();

                if ($histTypeId === 658) {
                    $valueCount = $decoder->readInt32();
                    for ($j = 0; $j < $valueCount; $j++) {
                        $allValues[] = $decoder->readDataValue();
                    }
                } else {
                    $consumed = $decoder->getOffset() - $bodyStart;
                    if ($consumed < $bodyLength) {
                        $decoder->skip($bodyLength - $consumed);
                    }
                }

                $consumed = $decoder->getOffset() - $bodyStart;
                if ($consumed < $bodyLength) {
                    $decoder->skip($bodyLength - $consumed);
                }
            }
        }

        $decoder->skipDiagnosticInfoArray();

        return ['values' => $allValues, 'continuationPoint' => $continuationPoint];
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId[] $nodeIds
     * @param int $detailsTypeId
     * @param string $detailsBody
     */
    private function writeHistoryReadInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        array $nodeIds,
        int $detailsTypeId,
        string $detailsBody,
        ?string $continuationPoint = null,
        bool $releaseContinuationPoints = false,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::HISTORY_READ_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeNodeId(NodeId::numeric(0, $detailsTypeId));
        $body->writeByte(0x01);
        $body->writeInt32(strlen($detailsBody));
        $body->writeRawBytes($detailsBody);

        $body->writeUInt32(2);

        $body->writeBoolean($releaseContinuationPoints);

        $body->writeInt32(count($nodeIds));
        foreach ($nodeIds as $nodeId) {
            $body->writeNodeId($nodeId);
            $body->writeString(null);
            $body->writeUInt16(0);
            $body->writeString(null);
            $body->writeByteString($continuationPoint);
        }
    }

    /**
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     */
    private function buildReadRawModifiedDetailsBody(
        ?DateTimeImmutable $startTime,
        ?DateTimeImmutable $endTime,
        int $numValuesPerNode,
        bool $returnBounds,
    ): string {
        $encoder = new BinaryEncoder();

        $encoder->writeBoolean(false);

        $encoder->writeDateTime($startTime);

        $encoder->writeDateTime($endTime);

        $encoder->writeUInt32($numValuesPerNode);

        $encoder->writeBoolean($returnBounds);

        return $encoder->getBuffer();
    }

    /**
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     */
    private function buildReadProcessedDetailsBody(
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): string {
        $encoder = new BinaryEncoder();

        $encoder->writeDateTime($startTime);

        $encoder->writeDateTime($endTime);

        $encoder->writeDouble($processingInterval);

        $encoder->writeInt32(1);
        $encoder->writeNodeId($aggregateType);

        $encoder->writeBoolean(true);
        $encoder->writeBoolean(true);
        $encoder->writeByte(100);
        $encoder->writeByte(100);
        $encoder->writeBoolean(false);

        return $encoder->getBuffer();
    }

    /**
     * @param DateTimeImmutable[] $timestamps
     */
    private function buildReadAtTimeDetailsBody(array $timestamps): string
    {
        $encoder = new BinaryEncoder();

        $encoder->writeInt32(count($timestamps));
        foreach ($timestamps as $ts) {
            $encoder->writeDateTime($ts);
        }

        $encoder->writeBoolean(true);

        return $encoder->getBuffer();
    }
}
