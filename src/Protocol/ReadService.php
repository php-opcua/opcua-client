<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class ReadService
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
     * @param int $attributeId
     */
    public function encodeReadRequest(int $requestId, NodeId $nodeId, NodeId $authToken, int $attributeId = 13): string
    {
        return $this->encodeReadMultiRequest($requestId, [['nodeId' => $nodeId, 'attributeId' => $attributeId]], $authToken);
    }

    /**
     * @param int $requestId
     * @param array<array{nodeId: NodeId, attributeId?: int}> $readItems
     * @param NodeId $authToken
     */
    public function encodeReadMultiRequest(int $requestId, array $readItems, NodeId $authToken): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeReadMultiRequestSecure($requestId, $readItems, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeReadInnerBody($body, $requestId, $readItems, $authToken);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public function decodeReadResponse(BinaryDecoder $decoder): DataValue
    {
        $results = $this->decodeReadMultiResponse($decoder);

        return $results[0] ?? new DataValue();
    }

    /**
     * @param BinaryDecoder $decoder
     * @return DataValue[]
     */
    public function decodeReadMultiResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $decoder->readDataValue();
        }

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

        return $results;
    }

    /**
     * @param int $requestId
     * @param array<array{nodeId: NodeId, attributeId?: int}> $readItems
     * @param NodeId $authToken
     */
    private function encodeReadMultiRequestSecure(int $requestId, array $readItems, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeReadInnerBody($body, $requestId, $readItems, $authToken);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array<array{nodeId: NodeId, attributeId?: int}> $readItems
     * @param NodeId $authToken
     */
    private function writeReadInnerBody(BinaryEncoder $body, int $requestId, array $readItems, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 631));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeDouble(0.0);

        $body->writeUInt32(2);

        $body->writeInt32(count($readItems));

        foreach ($readItems as $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeUInt32($item['attributeId'] ?? 13);
            $body->writeString(null);
            $body->writeUInt16(0);
            $body->writeString(null);
        }
    }

    /**
     * @param string $bodyBytes
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

    /**
     * @param BinaryDecoder $decoder
     */
    private function skipDiagnosticInfo(BinaryDecoder $decoder): void
    {
        $mask = $decoder->readByte();
        if ($mask & 0x01) {
            $decoder->readInt32();
        }
        if ($mask & 0x02) {
            $decoder->readInt32();
        }
        if ($mask & 0x04) {
            $decoder->readInt32();
        }
        if ($mask & 0x08) {
            $decoder->readString();
        }
        if ($mask & 0x10) {
            $decoder->readUInt32();
        }
        if ($mask & 0x20) {
            $this->skipDiagnosticInfo($decoder);
        }
    }
}
