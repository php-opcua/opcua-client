<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

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

        $decoder->skipDiagnosticInfoArray();

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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::WRITE_REQUEST));

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
