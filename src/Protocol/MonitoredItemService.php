<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\AttributeId;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemModifyResult;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\SetTriggeringResult;

class MonitoredItemService extends AbstractProtocolService
{
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
        $body = new BinaryEncoder();
        $this->writeCreateMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $items, $timestampsToReturn);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return MonitoredItemResult[]
     */
    public function decodeCreateMonitoredItemsResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::CREATE_MONITORED_ITEMS_REQUEST));

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
            $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
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
        $body = new BinaryEncoder();
        $this->writeCreateEventMonitoredItemInnerBody($body, $requestId, $authToken, $subscriptionId, $nodeId, $selectFields, $clientHandle);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::CREATE_MONITORED_ITEMS_REQUEST));

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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::EVENT_FILTER_ENCODING));
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
            $filter->writeNodeId(NodeId::numeric(0, ServiceTypeId::SIMPLE_ATTRIBUTE_OPERAND_ENCODING));

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
     * @param array<array{monitoredItemId: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, discardOldest?: bool}> $itemsToModify
     * @param int $timestampsToReturn
     * @return string
     */
    public function encodeModifyMonitoredItemsRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        array $itemsToModify,
        int $timestampsToReturn = 2,
    ): string {
        $body = new BinaryEncoder();

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::MODIFY_MONITORED_ITEMS_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);
        $body->writeUInt32($timestampsToReturn);

        $body->writeInt32(count($itemsToModify));
        foreach ($itemsToModify as $item) {
            $body->writeUInt32($item['monitoredItemId']);

            $body->writeUInt32($item['clientHandle'] ?? 0);
            $body->writeDouble($item['samplingInterval'] ?? -1.0);
            $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
            $body->writeByte(0x00);
            $body->writeUInt32($item['queueSize'] ?? 0);
            $body->writeBoolean($item['discardOldest'] ?? true);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return MonitoredItemModifyResult[]
     */
    public function decodeModifyMonitoredItemsResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $count = $decoder->readInt32();
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $statusCode = $decoder->readUInt32();
            $revisedSamplingInterval = $decoder->readDouble();
            $revisedQueueSize = $decoder->readUInt32();
            $decoder->readExtensionObject();

            $results[] = new MonitoredItemModifyResult($statusCode, $revisedSamplingInterval, $revisedQueueSize);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int $triggeringItemId
     * @param int[] $linksToAdd
     * @param int[] $linksToRemove
     * @return string
     */
    public function encodeSetTriggeringRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        int $triggeringItemId,
        array $linksToAdd = [],
        array $linksToRemove = [],
    ): string {
        $body = new BinaryEncoder();

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::SET_TRIGGERING_REQUEST));
        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);
        $body->writeUInt32($triggeringItemId);

        $body->writeInt32(count($linksToAdd));
        foreach ($linksToAdd as $id) {
            $body->writeUInt32($id);
        }

        $body->writeInt32(count($linksToRemove));
        foreach ($linksToRemove as $id) {
            $body->writeUInt32($id);
        }

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return SetTriggeringResult
     */
    public function decodeSetTriggeringResponse(BinaryDecoder $decoder): SetTriggeringResult
    {
        $this->readResponseMetadata($decoder);

        $addCount = $decoder->readInt32();
        $addResults = [];
        for ($i = 0; $i < $addCount; $i++) {
            $addResults[] = $decoder->readUInt32();
        }

        $decoder->skipDiagnosticInfoArray();

        $removeCount = $decoder->readInt32();
        $removeResults = [];
        for ($i = 0; $i < $removeCount; $i++) {
            $removeResults[] = $decoder->readUInt32();
        }

        $decoder->skipDiagnosticInfoArray();

        return new SetTriggeringResult($addResults, $removeResults);
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
        $body = new BinaryEncoder();
        $this->writeDeleteMonitoredItemsInnerBody($body, $requestId, $authToken, $subscriptionId, $monitoredItemIds);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteMonitoredItemsResponse(BinaryDecoder $decoder): array
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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::DELETE_MONITORED_ITEMS_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);

        $body->writeInt32(count($monitoredItemIds));
        foreach ($monitoredItemIds as $id) {
            $body->writeUInt32($id);
        }
    }
}
