<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

use DateTimeImmutable;
use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

class HistoryUpdateService extends AbstractProtocolService
{
    private const ENCODING_UPDATE_DATA = 682;

    private const ENCODING_UPDATE_EVENT = 685;

    private const ENCODING_DELETE_RAW_MODIFIED = 688;

    private const ENCODING_DELETE_AT_TIME = 691;

    private const ENCODING_DELETE_EVENT = 694;

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param PerformUpdateType $perform
     * @param DataValue[] $values
     */
    public function encodeUpdateDataRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        PerformUpdateType $perform,
        array $values,
    ): string {
        $details = $this->buildUpdateDataDetailsBody($nodeId, $perform, $values);

        return $this->encodeHistoryUpdateRequest($requestId, $authToken, self::ENCODING_UPDATE_DATA, $details);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param PerformUpdateType $perform
     * @param string[] $selectFields
     * @param array<int, Variant[]> $eventData
     */
    public function encodeUpdateEventRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        PerformUpdateType $perform,
        array $selectFields,
        array $eventData,
    ): string {
        $details = $this->buildUpdateEventDetailsBody($nodeId, $perform, $selectFields, $eventData);

        return $this->encodeHistoryUpdateRequest($requestId, $authToken, self::ENCODING_UPDATE_EVENT, $details);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param bool $isDeleteModified
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     */
    public function encodeDeleteRawModifiedRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        bool $isDeleteModified,
        ?DateTimeImmutable $startTime,
        ?DateTimeImmutable $endTime,
    ): string {
        $details = $this->buildDeleteRawModifiedDetailsBody($nodeId, $isDeleteModified, $startTime, $endTime);

        return $this->encodeHistoryUpdateRequest($requestId, $authToken, self::ENCODING_DELETE_RAW_MODIFIED, $details);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param DateTimeImmutable[] $timestamps
     */
    public function encodeDeleteAtTimeRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        array $timestamps,
    ): string {
        $details = $this->buildDeleteAtTimeDetailsBody($nodeId, $timestamps);

        return $this->encodeHistoryUpdateRequest($requestId, $authToken, self::ENCODING_DELETE_AT_TIME, $details);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param NodeId $nodeId
     * @param string[] $eventIds Raw ByteString EventIds (each is the binary EventId from a notification).
     */
    public function encodeDeleteEventRequest(
        int $requestId,
        NodeId $authToken,
        NodeId $nodeId,
        array $eventIds,
    ): string {
        $details = $this->buildDeleteEventDetailsBody($nodeId, $eventIds);

        return $this->encodeHistoryUpdateRequest($requestId, $authToken, self::ENCODING_DELETE_EVENT, $details);
    }

    /**
     * @param BinaryDecoder $decoder
     * @return HistoryUpdateResult[]
     */
    public function decodeHistoryUpdateResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $results = [];

        for ($i = 0; $i < $resultCount; $i++) {
            $statusCode = $decoder->readUInt32();

            $opCount = $decoder->readInt32();
            $ops = [];
            if ($opCount > 0) {
                for ($j = 0; $j < $opCount; $j++) {
                    $ops[] = $decoder->readUInt32();
                }
            }

            $decoder->skipDiagnosticInfoArray();

            $results[] = new HistoryUpdateResult($statusCode, $ops);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    private function encodeHistoryUpdateRequest(
        int $requestId,
        NodeId $authToken,
        int $detailsEncodingId,
        string $detailsBody,
    ): string {
        $body = new BinaryEncoder();
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::HISTORY_UPDATE_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(1);

        $body->writeNodeId(NodeId::numeric(0, $detailsEncodingId));
        $body->writeByte(0x01);
        $body->writeInt32(strlen($detailsBody));
        $body->writeRawBytes($detailsBody);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param DataValue[] $values
     */
    private function buildUpdateDataDetailsBody(NodeId $nodeId, PerformUpdateType $perform, array $values): string
    {
        $e = new BinaryEncoder();
        $e->writeNodeId($nodeId);
        $e->writeUInt32($perform->value);

        $e->writeInt32(count($values));
        foreach ($values as $dv) {
            $e->writeDataValue($dv);
        }

        return $e->getBuffer();
    }

    /**
     * @param string[] $selectFields
     * @param array<int, Variant[]> $eventData
     */
    private function buildUpdateEventDetailsBody(
        NodeId $nodeId,
        PerformUpdateType $perform,
        array $selectFields,
        array $eventData,
    ): string {
        $e = new BinaryEncoder();
        $e->writeNodeId($nodeId);
        $e->writeUInt32($perform->value);

        $e->writeInt32(count($selectFields));
        foreach ($selectFields as $fieldName) {
            $e->writeNodeId(NodeId::numeric(0, ServiceTypeId::SIMPLE_ATTRIBUTE_OPERAND_ENCODING));
            $e->writeInt32(1);
            $e->writeUInt16(0);
            $e->writeString($fieldName);
            $e->writeUInt32(13);
            $e->writeString(null);
        }

        $e->writeInt32(0);

        $e->writeInt32(count($eventData));
        foreach ($eventData as $eventFields) {
            $e->writeInt32(count($eventFields));
            foreach ($eventFields as $variant) {
                $e->writeVariant($variant);
            }
        }

        return $e->getBuffer();
    }

    private function buildDeleteRawModifiedDetailsBody(
        NodeId $nodeId,
        bool $isDeleteModified,
        ?DateTimeImmutable $startTime,
        ?DateTimeImmutable $endTime,
    ): string {
        $e = new BinaryEncoder();
        $e->writeNodeId($nodeId);
        $e->writeBoolean($isDeleteModified);
        $e->writeDateTime($startTime);
        $e->writeDateTime($endTime);

        return $e->getBuffer();
    }

    /**
     * @param DateTimeImmutable[] $timestamps
     */
    private function buildDeleteAtTimeDetailsBody(NodeId $nodeId, array $timestamps): string
    {
        $e = new BinaryEncoder();
        $e->writeNodeId($nodeId);

        $e->writeInt32(count($timestamps));
        foreach ($timestamps as $t) {
            $e->writeDateTime($t);
        }

        return $e->getBuffer();
    }

    /**
     * @param string[] $eventIds
     */
    private function buildDeleteEventDetailsBody(NodeId $nodeId, array $eventIds): string
    {
        $e = new BinaryEncoder();
        $e->writeNodeId($nodeId);

        $e->writeInt32(count($eventIds));
        foreach ($eventIds as $eid) {
            $e->writeByteString($eid);
        }

        return $e->getBuffer();
    }
}
