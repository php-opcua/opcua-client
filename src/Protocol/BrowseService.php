<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;

class BrowseService
{
    /**
     * @param SessionService $session
     */
    public function __construct(private readonly SessionService $session)
    {
    }

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
        int             $requestId,
        NodeId          $nodeId,
        NodeId          $authToken,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        int             $nodeClassMask = 0,
    ): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeBrowseRequestSecure(
                $requestId,
                $nodeId,
                $authToken,
                $direction,
                $referenceTypeId,
                $includeSubtypes,
                $nodeClassMask,
            );
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeBrowseInnerBody($body, $requestId, $nodeId, $authToken, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function decodeBrowseResponseWithContinuation(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

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

        return [
            'references' => $references,
            'continuationPoint' => $continuationPoint,
        ];
    }

    /**
     * @param BinaryDecoder $decoder
     * @return ReferenceDescription[]
     */
    public function decodeBrowseResponse(BinaryDecoder $decoder): array
    {
        $result = $this->decodeBrowseResponseWithContinuation($decoder);

        return $result['references'];
    }

    /**
     * @param int $requestId
     * @param string $continuationPoint
     * @param NodeId $authToken
     */
    public function encodeBrowseNextRequest(int $requestId, string $continuationPoint, NodeId $authToken): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeBrowseNextRequestSecure($requestId, $continuationPoint, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $body->writeNodeId(NodeId::numeric(0, 533));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeBoolean(false);

        $body->writeInt32(1);
        $body->writeByteString($continuationPoint);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{references: ReferenceDescription[], continuationPoint: ?string}
     */
    public function decodeBrowseNextResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

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

        return [
            'references' => $references,
            'continuationPoint' => $continuationPoint,
        ];
    }

    /**
     * @param int $requestId
     * @param NodeId $nodeId
     * @param NodeId $authToken
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param int $nodeClassMask
     */
    private function encodeBrowseRequestSecure(
        int             $requestId,
        NodeId          $nodeId,
        NodeId          $authToken,
        BrowseDirection $direction,
        ?NodeId         $referenceTypeId,
        bool            $includeSubtypes,
        int             $nodeClassMask,
    ): string
    {
        $body = new BinaryEncoder();
        $this->writeBrowseInnerBody($body, $requestId, $nodeId, $authToken, $direction, $referenceTypeId, $includeSubtypes, $nodeClassMask);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param int $requestId
     * @param string $continuationPoint
     * @param NodeId $authToken
     * @return string
     */
    private function encodeBrowseNextRequestSecure(int $requestId, string $continuationPoint, NodeId $authToken): string
    {
        $body = new BinaryEncoder();

        $body->writeNodeId(NodeId::numeric(0, 533));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeBoolean(false);

        $body->writeInt32(1);
        $body->writeByteString($continuationPoint);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
        BinaryEncoder   $body,
        int             $requestId,
        NodeId          $nodeId,
        NodeId          $authToken,
        BrowseDirection $direction,
        ?NodeId         $referenceTypeId,
        bool            $includeSubtypes,
        int             $nodeClassMask,
    ): void
    {
        $body->writeNodeId(NodeId::numeric(0, 527));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

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
     * @param string $bodyBytes
     * @return string
     */
    private function wrapInMessage(string $bodyBytes): string
    {
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($this->session->getSecureChannelId());
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }
}
