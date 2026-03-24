<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class WriteService extends AbstractProtocolService
{
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
        $body = new BinaryEncoder();
        $this->writeWriteInnerBody($body, $requestId, $writeItems, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeWriteResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

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
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array<array{nodeId: NodeId, dataValue: DataValue, attributeId?: int}> $writeItems
     * @param NodeId $authToken
     */
    private function writeWriteInnerBody(BinaryEncoder $body, int $requestId, array $writeItems, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 673));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($writeItems));

        foreach ($writeItems as $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeUInt32($item['attributeId'] ?? AttributeId::Value);
            $body->writeString(null);

            $body->writeDataValue($item['dataValue']);
        }
    }
}
