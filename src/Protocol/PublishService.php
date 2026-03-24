<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\PublishResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class PublishService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     */
    public function encodePublishRequest(
        int $requestId,
        NodeId $authToken,
        array $acknowledgements = [],
    ): string {
        $body = new BinaryEncoder();
        $this->writePublishInnerBody($body, $requestId, $authToken, $acknowledgements);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return PublishResult
     */
    public function decodePublishResponse(BinaryDecoder $decoder): PublishResult
    {
        $this->readResponseMetadata($decoder);

        $subscriptionId = $decoder->readUInt32();

        $availSeqCount = $decoder->readInt32();
        $availableSequenceNumbers = [];
        for ($i = 0; $i < $availSeqCount; $i++) {
            $availableSequenceNumbers[] = $decoder->readUInt32();
        }

        $moreNotifications = $decoder->readBoolean();

        $sequenceNumber = $decoder->readUInt32();
        $publishTime = $decoder->readDateTime();

        $notifCount = $decoder->readInt32();
        $notifications = [];

        for ($i = 0; $i < $notifCount; $i++) {
            $typeId = $decoder->readNodeId();
            $encoding = $decoder->readByte();

            if ($encoding === 0x01) {
                $bodyLength = $decoder->readInt32();
                $bodyStart = $decoder->getOffset();

                $notifTypeId = $typeId->getIdentifier();

                if ($notifTypeId === 811) {
                    $notifications = array_merge($notifications, $this->decodeDataChangeNotification($decoder));
                } elseif ($notifTypeId === 916) {
                    $notifications = array_merge($notifications, $this->decodeEventNotificationList($decoder));
                } else {
                    $decoder->skip($bodyLength - ($decoder->getOffset() - $bodyStart));
                }

                $consumed = $decoder->getOffset() - $bodyStart;
                if ($consumed < $bodyLength) {
                    $decoder->skip($bodyLength - $consumed);
                }
            } elseif ($encoding === 0x00) {
            }
        }

        $resultCount = $decoder->readInt32();
        for ($i = 0; $i < $resultCount; $i++) {
            $decoder->readUInt32();
        }

        $decoder->skipDiagnosticInfoArray();

        return new PublishResult($subscriptionId, $sequenceNumber, $moreNotifications, $notifications, $availableSequenceNumbers);
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array<array{type: string, clientHandle: int, dataValue: DataValue}>
     */
    private function decodeDataChangeNotification(BinaryDecoder $decoder): array
    {
        $count = $decoder->readInt32();
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $clientHandle = $decoder->readUInt32();
            $dataValue = $decoder->readDataValue();

            $items[] = [
                'type' => 'DataChange',
                'clientHandle' => $clientHandle,
                'dataValue' => $dataValue,
            ];
        }

        $decoder->skipDiagnosticInfoArray();

        return $items;
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array<array{type: string, clientHandle: int, eventFields: Variant[]}>
     */
    private function decodeEventNotificationList(BinaryDecoder $decoder): array
    {
        $count = $decoder->readInt32();
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $clientHandle = $decoder->readUInt32();

            $fieldCount = $decoder->readInt32();
            $fields = [];
            for ($j = 0; $j < $fieldCount; $j++) {
                $fields[] = $decoder->readVariant();
            }

            $events[] = [
                'type' => 'Event',
                'clientHandle' => $clientHandle,
                'eventFields' => $fields,
            ];
        }

        return $events;
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     */
    private function writePublishInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        array $acknowledgements,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 826));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($acknowledgements));
        foreach ($acknowledgements as $ack) {
            $body->writeUInt32($ack['subscriptionId']);
            $body->writeUInt32($ack['sequenceNumber']);
        }
    }
}
