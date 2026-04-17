<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\ReadWrite;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;

class ReadService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $nodeId
     * @param NodeId $authToken
     * @param int $attributeId
     */
    public function encodeReadRequest(int $requestId, NodeId $nodeId, NodeId $authToken, int $attributeId = AttributeId::Value): string
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
        $body = new BinaryEncoder();
        $this->writeReadInnerBody($body, $requestId, $readItems, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
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
        $this->readResponseMetadata($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $decoder->readDataValue();
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array<array{nodeId: NodeId, attributeId?: int}> $readItems
     * @param NodeId $authToken
     */
    private function writeReadInnerBody(BinaryEncoder $body, int $requestId, array $readItems, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::READ_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeDouble(0.0);

        $body->writeUInt32(2);

        $body->writeInt32(count($readItems));

        foreach ($readItems as $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeUInt32($item['attributeId'] ?? AttributeId::Value);
            $body->writeString(null);
            $body->writeUInt16(0);
            $body->writeString(null);
        }
    }
}
