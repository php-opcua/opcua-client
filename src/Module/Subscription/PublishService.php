<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\NodeId;

class PublishService extends AbstractProtocolService
{
    use DecodesNotificationDataTrait;

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

        $notifications = $this->decodeNotificationData($decoder);

        $resultCount = $decoder->readInt32();
        $acknowledgementResults = [];
        for ($i = 0; $i < $resultCount; $i++) {
            $acknowledgementResults[] = $decoder->readUInt32();
        }

        $decoder->skipDiagnosticInfoArray();

        return new PublishResult($subscriptionId, $sequenceNumber, $moreNotifications, $notifications, $availableSequenceNumbers, $publishTime, $acknowledgementResults);
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
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::PUBLISH_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($acknowledgements));
        foreach ($acknowledgements as $ack) {
            $body->writeUInt32($ack['subscriptionId']);
            $body->writeUInt32($ack['sequenceNumber']);
        }
    }
}
