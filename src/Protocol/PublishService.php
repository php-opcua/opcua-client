<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class PublishService
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
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     */
    public function encodePublishRequest(
        int $requestId,
        NodeId $authToken,
        array $acknowledgements = [],
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodePublishRequestSecure($requestId, $authToken, $acknowledgements);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writePublishInnerBody($body, $requestId, $authToken, $acknowledgements);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{subscriptionId: int, sequenceNumber: int, moreNotifications: bool, notifications: array, availableSequenceNumbers: int[]}
     */
    public function decodePublishResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

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

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

        return [
            'subscriptionId' => $subscriptionId,
            'sequenceNumber' => $sequenceNumber,
            'moreNotifications' => $moreNotifications,
            'notifications' => $notifications,
            'availableSequenceNumbers' => $availableSequenceNumbers,
        ];
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

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

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
     * @param int $requestId
     * @param NodeId $authToken
     * @param array<array{subscriptionId: int, sequenceNumber: int}> $acknowledgements
     */
    private function encodePublishRequestSecure(
        int $requestId,
        NodeId $authToken,
        array $acknowledgements,
    ): string {
        $body = new BinaryEncoder();
        $this->writePublishInnerBody($body, $requestId, $authToken, $acknowledgements);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
