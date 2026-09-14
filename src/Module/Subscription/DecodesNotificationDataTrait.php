<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Encoding\BinaryDecoder;

/**
 * Decodes the NotificationData array of an OPC UA NotificationMessage. Publish and
 * Republish responses carry the same structure, so both services share this code.
 */
trait DecodesNotificationDataTrait
{
    private const DATA_CHANGE_NOTIFICATION_ENCODING = 811;

    private const EVENT_NOTIFICATION_LIST_ENCODING = 916;

    /**
     * Reads the NotificationData ExtensionObject array. DataChangeNotification and
     * EventNotificationList bodies are decoded; other notification types
     * (StatusChangeNotification, …), XML-encoded and empty bodies are skipped
     * without desynchronising the decoder.
     *
     * @param BinaryDecoder $decoder
     * @return array<int, DataChangeNotification|EventNotification>
     */
    private function decodeNotificationData(BinaryDecoder $decoder): array
    {
        $count = $decoder->readInt32();
        $notifications = [];

        for ($i = 0; $i < $count; $i++) {
            $typeId = $decoder->readNodeId();
            $encoding = $decoder->readByte();

            if ($encoding === 0x00) {
                continue;
            }

            $bodyLength = $decoder->readInt32();
            if ($bodyLength <= 0) {
                continue;
            }

            $bodyStart = $decoder->getOffset();

            if ($encoding === 0x01) {
                $notifications = array_merge($notifications, $this->decodeNotificationBody($typeId->getIdentifier(), $decoder));
            }

            $consumed = $decoder->getOffset() - $bodyStart;
            if ($consumed < $bodyLength) {
                $decoder->skip($bodyLength - $consumed);
            }
        }

        return $notifications;
    }

    /**
     * @param int|string $typeIdentifier
     * @param BinaryDecoder $decoder
     * @return array<int, DataChangeNotification|EventNotification>
     */
    private function decodeNotificationBody(int|string $typeIdentifier, BinaryDecoder $decoder): array
    {
        return match ($typeIdentifier) {
            self::DATA_CHANGE_NOTIFICATION_ENCODING => $this->decodeDataChangeNotification($decoder),
            self::EVENT_NOTIFICATION_LIST_ENCODING => $this->decodeEventNotificationList($decoder),
            default => [],
        };
    }

    /**
     * @param BinaryDecoder $decoder
     * @return DataChangeNotification[]
     */
    private function decodeDataChangeNotification(BinaryDecoder $decoder): array
    {
        $count = $decoder->readInt32();
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $clientHandle = $decoder->readUInt32();
            $dataValue = $decoder->readDataValue();

            $items[] = new DataChangeNotification($clientHandle, $dataValue);
        }

        $decoder->skipDiagnosticInfoArray();

        return $items;
    }

    /**
     * @param BinaryDecoder $decoder
     * @return EventNotification[]
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

            $events[] = new EventNotification($clientHandle, $fields);
        }

        return $events;
    }
}
