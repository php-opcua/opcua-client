<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class MonitoredItemService
{
    /**
     * @param SessionService $session
     */
    public function __construct(private readonly SessionService $session)
    {
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param array<array{nodeId: NodeId, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @param int $timestampsToReturn
     */
    public function encodeCreateMonitoredItemsRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $items,
        int $timestampsToReturn = 2,
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeCreateMonitoredItemsRequestSecure(
                $requestId,
                $authToken,
                $subscriptionId,
                $items,
                $timestampsToReturn,
            );
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeCreateMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $items, $timestampsToReturn);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return MonitoredItemResult[]
     */
    public function decodeCreateMonitoredItemsResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $statusCode = $decoder->readUInt32();
            $monitoredItemId = $decoder->readUInt32();
            $revisedSamplingInterval = $decoder->readDouble();
            $revisedQueueSize = $decoder->readUInt32();
            $decoder->readExtensionObject();

            $results[] = new MonitoredItemResult($statusCode, $monitoredItemId, $revisedSamplingInterval, $revisedQueueSize);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param array<array{nodeId: NodeId, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @param int $timestampsToReturn
     */
    private function encodeCreateMonitoredItemsRequestSecure(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $items,
        int $timestampsToReturn,
    ): string {
        $body = new BinaryEncoder();
        $this->writeCreateMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $items, $timestampsToReturn);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param array<array{nodeId: NodeId, attributeId?: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, monitoringMode?: int}> $items
     * @param int $timestampsToReturn
     */
    private function writeCreateMonitoredItemsInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $items,
        int $timestampsToReturn,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 751));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);

        $body->writeUInt32($timestampsToReturn);

        $body->writeInt32(count($items));

        foreach ($items as $index => $item) {
            $body->writeNodeId($item['nodeId']);
            $body->writeUInt32($item['attributeId'] ?? AttributeId::Value);
            $body->writeString(null);
            $body->writeUInt16(0);
            $body->writeString(null);

            $body->writeUInt32($item['monitoringMode'] ?? 2);

            $body->writeUInt32($item['clientHandle'] ?? $index + 1);
            $body->writeDouble($item['samplingInterval'] ?? -1.0);
            $body->writeNodeId(NodeId::numeric(0, 0));
            $body->writeByte(0x00);
            $body->writeUInt32($item['queueSize'] ?? 1);
            $body->writeBoolean(true);
        }
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param NodeId $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     */
    public function encodeCreateEventMonitoredItemRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        NodeId $nodeId,
        array $selectFields,
        int $clientHandle = 1,
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeCreateEventMonitoredItemRequestSecure(
                $requestId,
                $authToken,
                $subscriptionId,
                $nodeId,
                $selectFields,
                $clientHandle,
            );
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeCreateEventMonitoredItemInnerBody($body, $requestId, $authToken, $subscriptionId, $nodeId, $selectFields, $clientHandle);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param NodeId $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     */
    private function encodeCreateEventMonitoredItemRequestSecure(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        NodeId $nodeId,
        array $selectFields,
        int $clientHandle,
    ): string {
        $body = new BinaryEncoder();
        $this->writeCreateEventMonitoredItemInnerBody($body, $requestId, $authToken, $subscriptionId, $nodeId, $selectFields, $clientHandle);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param NodeId $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     */
    private function writeCreateEventMonitoredItemInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        NodeId $nodeId,
        array $selectFields,
        int $clientHandle,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 751));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);

        $body->writeUInt32(2);

        $body->writeInt32(1);

        $body->writeNodeId($nodeId);
        $body->writeUInt32(12);
        $body->writeString(null);
        $body->writeUInt16(0);
        $body->writeString(null);

        $body->writeUInt32(2);

        $body->writeUInt32($clientHandle);
        $body->writeDouble(0.0);

        $filterBody = $this->buildEventFilterBody($selectFields);
        $body->writeNodeId(NodeId::numeric(0, 727));
        $body->writeByte(0x01);
        $body->writeInt32(strlen($filterBody));
        $body->writeRawBytes($filterBody);

        $body->writeUInt32(0);
        $body->writeBoolean(true);
    }

    /**
     * @param string[] $selectFields
     */
    private function buildEventFilterBody(array $selectFields): string
    {
        $filter = new BinaryEncoder();

        $filter->writeInt32(count($selectFields));

        foreach ($selectFields as $fieldName) {
            $filter->writeNodeId(NodeId::numeric(0, 2041));

            $filter->writeInt32(1);
            $filter->writeUInt16(0);
            $filter->writeString($fieldName);

            $filter->writeUInt32(13);

            $filter->writeString(null);
        }

        $filter->writeInt32(0);

        return $filter->getBuffer();
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     */
    public function encodeDeleteMonitoredItemsRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $monitoredItemIds,
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeDeleteMonitoredItemsRequestSecure($requestId, $authToken, $subscriptionId, $monitoredItemIds);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeDeleteMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $monitoredItemIds);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteMonitoredItemsResponse(BinaryDecoder $decoder): array
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

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     */
    private function encodeDeleteMonitoredItemsRequestSecure(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $monitoredItemIds,
    ): string {
        $body = new BinaryEncoder();
        $this->writeDeleteMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $monitoredItemIds);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     */
    private function writeDeleteMonitoredItemsInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $monitoredItemIds,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 781));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);

        $body->writeInt32(count($monitoredItemIds));
        foreach ($monitoredItemIds as $id) {
            $body->writeUInt32($id);
        }
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     */
    private function writeRequestHeader(BinaryEncoder $body, int $requestId, NodeId $authToken): void
    {
        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);
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
