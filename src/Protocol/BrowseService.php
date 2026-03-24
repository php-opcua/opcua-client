<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

class BrowseService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $nodeId
     * @param NodeId $authToken
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     */
    public function encodeBrowseRequest(
        int $requestId,
        NodeId $nodeId,
        NodeId $authToken,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        int $nodeClassMask = 0,
    ): string {
        $body = new BinaryEncoder();
        $this->writeBrowseInnerBody($body, $requestId, $nodeId, $authToken, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return BrowseResultSet
     */
    public function decodeBrowseResponseWithContinuation(BinaryDecoder $decoder): BrowseResultSet
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $references = [];
        $continuationPoint = null;

        for ($i = 0; $i < $resultCount; $i++) {
            $decoder->readUInt32();
            $continuationPoint = $decoder->readByteString();

            $refCount = $decoder->readInt32();
            for ($j = 0; $j < $refCount; $j++) {
                $references[] = $decoder->readReferenceDescription();
            }
        }

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $decoder->readByte();
        }

        return new BrowseResultSet($references, $continuationPoint);
    }

    /**
     * @param BinaryDecoder $decoder
     * @return ReferenceDescription[]
     */
    public function decodeBrowseResponse(BinaryDecoder $decoder): array
    {
        $result = $this->decodeBrowseResponseWithContinuation($decoder);

        return $result->references;
    }

    /**
     * @param int $requestId
     * @param string $continuationPoint
     * @param NodeId $authToken
     */
    public function encodeBrowseNextRequest(int $requestId, string $continuationPoint, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeBrowseNextInnerBody($body, $requestId, $continuationPoint, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return BrowseResultSet
     */
    public function decodeBrowseNextResponse(BinaryDecoder $decoder): BrowseResultSet
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $references = [];
        $continuationPoint = null;

        for ($i = 0; $i < $resultCount; $i++) {
            $decoder->readUInt32();
            $continuationPoint = $decoder->readByteString();

            $refCount = $decoder->readInt32();
            for ($j = 0; $j < $refCount; $j++) {
                $references[] = $decoder->readReferenceDescription();
            }
        }

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $decoder->readByte();
        }

        return new BrowseResultSet($references, $continuationPoint);
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $nodeId
     * @param NodeId $authToken
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     */
    private function writeBrowseInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $nodeId,
        NodeId $authToken,
        BrowseDirection $direction,
        ?NodeId $referenceTypeId,
        bool $includeSubtypes,
        int $nodeClassMask,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 527));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeInt64(0);
        $body->writeUInt32(0);

        $body->writeUInt32(0);

        $body->writeInt32(1);

        $body->writeNodeId($nodeId);
        $body->writeUInt32($direction->value);
        $body->writeNodeId($referenceTypeId ?? NodeId::numeric(0, 33));
        $body->writeBoolean($includeSubtypes);
        $body->writeUInt32($nodeClassMask);
        $body->writeUInt32(0x3F);
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param string $continuationPoint
     * @param NodeId $authToken
     */
    private function writeBrowseNextInnerBody(BinaryEncoder $body, int $requestId, string $continuationPoint, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 533));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeBoolean(false);

        $body->writeInt32(1);
        $body->writeByteString($continuationPoint);
    }
}
