<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\NodeId;

class SubscriptionService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     * @return string
     */
    public function encodeCreateSubscriptionRequest(
        int $requestId,
        NodeId $authToken,
        float $requestedPublishingInterval = 500.0,
        int $requestedLifetimeCount = 2400,
        int $requestedMaxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        bool $publishingEnabled = true,
        int $priority = 0,
    ): string {
        $body = new BinaryEncoder();
        $this->writeCreateSubscriptionInnerBody(
            $body,
            $requestId,
            $authToken,
            $requestedPublishingInterval,
            $requestedLifetimeCount,
            $requestedMaxKeepAliveCount,
            $maxNotificationsPerPublish,
            $publishingEnabled,
            $priority,
        );

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return SubscriptionResult
     */
    public function decodeCreateSubscriptionResponse(BinaryDecoder $decoder): SubscriptionResult
    {
        $this->readResponseMetadata($decoder);

        $subscriptionId = $decoder->readUInt32();
        $revisedPublishingInterval = $decoder->readDouble();
        $revisedLifetimeCount = $decoder->readUInt32();
        $revisedMaxKeepAliveCount = $decoder->readUInt32();

        return new SubscriptionResult($subscriptionId, $revisedPublishingInterval, $revisedLifetimeCount, $revisedMaxKeepAliveCount);
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     */
    private function writeCreateSubscriptionInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        float $requestedPublishingInterval,
        int $requestedLifetimeCount,
        int $requestedMaxKeepAliveCount,
        int $maxNotificationsPerPublish,
        bool $publishingEnabled,
        int $priority,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::CREATE_SUBSCRIPTION_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeDouble($requestedPublishingInterval);
        $body->writeUInt32($requestedLifetimeCount);
        $body->writeUInt32($requestedMaxKeepAliveCount);
        $body->writeUInt32($maxNotificationsPerPublish);
        $body->writeBoolean($publishingEnabled);
        $body->writeByte($priority);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param int $priority
     * @return string
     */
    public function encodeModifySubscriptionRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        float $requestedPublishingInterval = 500.0,
        int $requestedLifetimeCount = 2400,
        int $requestedMaxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        int $priority = 0,
    ): string {
        $body = new BinaryEncoder();
        $this->writeModifySubscriptionInnerBody(
            $body,
            $requestId,
            $authToken,
            $subscriptionId,
            $requestedPublishingInterval,
            $requestedLifetimeCount,
            $requestedMaxKeepAliveCount,
            $maxNotificationsPerPublish,
            $priority,
        );

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{revisedPublishingInterval: float, revisedLifetimeCount: int, revisedMaxKeepAliveCount: int}
     */
    public function decodeModifySubscriptionResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $revisedPublishingInterval = $decoder->readDouble();
        $revisedLifetimeCount = $decoder->readUInt32();
        $revisedMaxKeepAliveCount = $decoder->readUInt32();

        return [
            'revisedPublishingInterval' => $revisedPublishingInterval,
            'revisedLifetimeCount' => $revisedLifetimeCount,
            'revisedMaxKeepAliveCount' => $revisedMaxKeepAliveCount,
        ];
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param int $priority
     */
    private function writeModifySubscriptionInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        float $requestedPublishingInterval,
        int $requestedLifetimeCount,
        int $requestedMaxKeepAliveCount,
        int $maxNotificationsPerPublish,
        int $priority,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::MODIFY_SUBSCRIPTION_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);
        $body->writeDouble($requestedPublishingInterval);
        $body->writeUInt32($requestedLifetimeCount);
        $body->writeUInt32($requestedMaxKeepAliveCount);
        $body->writeUInt32($maxNotificationsPerPublish);
        $body->writeByte($priority);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int[] $subscriptionIds
     * @return string
     */
    public function encodeDeleteSubscriptionsRequest(
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
    ): string {
        $body = new BinaryEncoder();
        $this->writeDeleteSubscriptionsInnerBody($body, $requestId, $authToken, $subscriptionIds);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteSubscriptionsResponse(BinaryDecoder $decoder): array
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
     * @param int[] $subscriptionIds
     */
    private function writeDeleteSubscriptionsInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::DELETE_SUBSCRIPTIONS_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($subscriptionIds));
        foreach ($subscriptionIds as $id) {
            $body->writeUInt32($id);
        }
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param bool $publishingEnabled
     * @param int[] $subscriptionIds
     * @return string
     */
    public function encodeSetPublishingModeRequest(
        int $requestId,
        NodeId $authToken,
        bool $publishingEnabled,
        array $subscriptionIds,
    ): string {
        $body = new BinaryEncoder();
        $this->writeSetPublishingModeInnerBody($body, $requestId, $authToken, $publishingEnabled, $subscriptionIds);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeSetPublishingModeResponse(BinaryDecoder $decoder): array
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
     * @param bool $publishingEnabled
     * @param int[] $subscriptionIds
     */
    private function writeSetPublishingModeInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        bool $publishingEnabled,
        array $subscriptionIds,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::SET_PUBLISHING_MODE_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeBoolean($publishingEnabled);

        $body->writeInt32(count($subscriptionIds));
        foreach ($subscriptionIds as $id) {
            $body->writeUInt32($id);
        }
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int[] $subscriptionIds
     * @param bool $sendInitialValues
     * @return string
     */
    public function encodeTransferSubscriptionsRequest(
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
        bool $sendInitialValues = false,
    ): string {
        $body = new BinaryEncoder();
        $this->writeTransferSubscriptionsInnerBody($body, $requestId, $authToken, $subscriptionIds, $sendInitialValues);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return TransferResult[]
     */
    public function decodeTransferSubscriptionsResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $resultCount = $decoder->readInt32();
        $results = [];

        for ($i = 0; $i < $resultCount; $i++) {
            $statusCode = $decoder->readUInt32();
            $seqCount = $decoder->readInt32();
            $seqNumbers = [];
            for ($j = 0; $j < $seqCount; $j++) {
                $seqNumbers[] = $decoder->readUInt32();
            }
            $results[] = new TransferResult($statusCode, $seqNumbers);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int[] $subscriptionIds
     * @param bool $sendInitialValues
     */
    private function writeTransferSubscriptionsInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
        bool $sendInitialValues,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::TRANSFER_SUBSCRIPTIONS_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($subscriptionIds));
        foreach ($subscriptionIds as $id) {
            $body->writeUInt32($id);
        }
        $body->writeBoolean($sendInitialValues);
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int $retransmitSequenceNumber
     * @return string
     */
    public function encodeRepublishRequest(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        int $retransmitSequenceNumber,
    ): string {
        $body = new BinaryEncoder();
        $this->writeRepublishInnerBody($body, $requestId, $authToken, $subscriptionId, $retransmitSequenceNumber);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{sequenceNumber: int, publishTime: ?\DateTimeImmutable, notifications: array}
     */
    public function decodeRepublishResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

        $sequenceNumber = $decoder->readUInt32();
        $publishTime = $decoder->readDateTime();

        $notifCount = $decoder->readInt32();
        $notifications = [];
        for ($i = 0; $i < $notifCount; $i++) {
            $decoder->readNodeId();
            $decoder->readByte();
            $bodyLen = $decoder->readInt32();
            if ($bodyLen > 0) {
                $decoder->skip($bodyLen);
            }
        }

        return [
            'sequenceNumber' => $sequenceNumber,
            'publishTime' => $publishTime,
            'notifications' => $notifications,
        ];
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param int $retransmitSequenceNumber
     */
    private function writeRepublishInnerBody(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        int $retransmitSequenceNumber,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::REPUBLISH_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeUInt32($subscriptionId);
        $body->writeUInt32($retransmitSequenceNumber);
    }
}
