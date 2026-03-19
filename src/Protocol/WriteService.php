<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class WriteService
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
     * @param DataValue $dataValue
     * @param NodeId $authToken
     */
    public function encodeWriteRequest(int $requestId, NodeId $nodeId, DataValue $dataValue, NodeId $authToken): string
    {
        return $this->encodeWriteMultiRequest($requestId, [['nodeId' => $nodeId, 'dataValue' => $dataValue]], $authToken);
    }

    /**
     * @param int $requestId
     * @param array<array{nodeId: NodeId, dataValue: DataValue, attributeId?: int}> $writeItems
     * @param NodeId $authToken
     */
    public function encodeWriteMultiRequest(int $requestId, array $writeItems, NodeId $authToken): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeWriteMultiRequestSecure($requestId, $writeItems, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeWriteInnerBody($body, $requestId, $writeItems, $authToken);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeWriteResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $decoder->readUInt32();
        }

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $decoder->readByte();
        }

        return $results;
    }

    /**
     * @param int $requestId
     * @param array<array{nodeId: NodeId, dataValue: DataValue, attributeId?: int}> $writeItems
     * @param NodeId $authToken
     */
    private function encodeWriteMultiRequestSecure(int $requestId, array $writeItems, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeWriteInnerBody($body, $requestId, $writeItems, $authToken);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array<array{nodeId: NodeId, dataValue: DataValue, attributeId?: int}> $writeItems
     * @param NodeId $authToken
     */
    private function writeWriteInnerBody(BinaryEncoder $body, int $requestId, array $writeItems, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 673));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeInt32(count($writeItems));

        foreach ($writeItems as $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeUInt32($item['attributeId'] ?? 13);
            $body->writeString(null);

            $body->writeDataValue($item['dataValue']);
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
}
