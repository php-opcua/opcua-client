<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

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
    ): string {
        $detailsBody = $this->buildReadRawModifiedDetailsBody($startTime, $endTime, $numValuesPerNode, $returnBounds);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 649, $detailsBody);
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
    ): string {
        $detailsBody = $this->buildReadProcessedDetailsBody($startTime, $endTime, $processingInterval, $aggregateType);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 652, $detailsBody);
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
    ): string {
        $detailsBody = $this->buildReadAtTimeDetailsBody($timestamps);

        return $this->encodeHistoryReadRequest($requestId, $authToken, [$nodeId], 655, $detailsBody);
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
    ): string {
        $body = new BinaryEncoder();
        $this->writeHistoryReadInnerBody($body, $requestId, $authToken, $nodeIds, $detailsTypeId, $detailsBody);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return DataValue[]
     */
    public function decodeHistoryReadResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $allValues = [];

        for ($i = 0; $i < $resultCount; $i++) {
            $decoder->readUInt32();

            $decoder->readByteString();

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

        return $allValues;
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
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 664));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeNodeId(NodeId::numeric(0, $detailsTypeId));
        $body->writeByte(0x01);
        $body->writeInt32(strlen($detailsBody));
        $body->writeRawBytes($detailsBody);

        $body->writeUInt32(2);

        $body->writeBoolean(false);

        $body->writeInt32(count($nodeIds));
        foreach ($nodeIds as $nodeId) {
            $body->writeNodeId($nodeId);
            $body->writeString(null);
            $body->writeUInt16(0);
            $body->writeString(null);
            $body->writeByteString(null);
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
