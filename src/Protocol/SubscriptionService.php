<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class SubscriptionService
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
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
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
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeCreateSubscriptionRequestSecure(
                $requestId,
                $authToken,
                $requestedPublishingInterval,
                $requestedLifetimeCount,
                $requestedMaxKeepAliveCount,
                $maxNotificationsPerPublish,
                $publishingEnabled,
                $priority,
            );
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

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

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{subscriptionId: int, revisedPublishingInterval: float, revisedLifetimeCount: int, revisedMaxKeepAliveCount: int}
     */
    public function decodeCreateSubscriptionResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $subscriptionId = $decoder->readUInt32();
        $revisedPublishingInterval = $decoder->readDouble();
        $revisedLifetimeCount = $decoder->readUInt32();
        $revisedMaxKeepAliveCount = $decoder->readUInt32();

        return [
            'subscriptionId' => $subscriptionId,
            'revisedPublishingInterval' => $revisedPublishingInterval,
            'revisedLifetimeCount' => $revisedLifetimeCount,
            'revisedMaxKeepAliveCount' => $revisedMaxKeepAliveCount,
        ];
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     */
    private function encodeCreateSubscriptionRequestSecure(
        int $requestId,
        NodeId $authToken,
        float $requestedPublishingInterval,
        int $requestedLifetimeCount,
        int $requestedMaxKeepAliveCount,
        int $maxNotificationsPerPublish,
        bool $publishingEnabled,
        int $priority,
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

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
        $body->writeNodeId(NodeId::numeric(0, 787));

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
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeModifySubscriptionRequestSecure(
                $requestId,
                $authToken,
                $subscriptionId,
                $requestedPublishingInterval,
                $requestedLifetimeCount,
                $requestedMaxKeepAliveCount,
                $maxNotificationsPerPublish,
                $priority,
            );
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

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

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{revisedPublishingInterval: float, revisedLifetimeCount: int, revisedMaxKeepAliveCount: int}
     */
    public function decodeModifySubscriptionResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

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
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $subscriptionId
     * @param float $requestedPublishingInterval
     * @param int $requestedLifetimeCount
     * @param int $requestedMaxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param int $priority
     */
    private function encodeModifySubscriptionRequestSecure(
        int $requestId,
        NodeId $authToken,
        int $subscriptionId,
        float $requestedPublishingInterval,
        int $requestedLifetimeCount,
        int $requestedMaxKeepAliveCount,
        int $maxNotificationsPerPublish,
        int $priority,
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

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
        $body->writeNodeId(NodeId::numeric(0, 793));

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
     */
    public function encodeDeleteSubscriptionsRequest(
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeDeleteSubscriptionsRequestSecure($requestId, $authToken, $subscriptionIds);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeDeleteSubscriptionsInnerBody($body, $requestId, $authToken, $subscriptionIds);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeDeleteSubscriptionsResponse(BinaryDecoder $decoder): array
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

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

        return $results;
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param int[] $subscriptionIds
     */
    private function encodeDeleteSubscriptionsRequestSecure(
        int $requestId,
        NodeId $authToken,
        array $subscriptionIds,
    ): string {
        $body = new BinaryEncoder();
        $this->writeDeleteSubscriptionsInnerBody($body, $requestId, $authToken, $subscriptionIds);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
        $body->writeNodeId(NodeId::numeric(0, 847));

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
     */
    public function encodeSetPublishingModeRequest(
        int $requestId,
        NodeId $authToken,
        bool $publishingEnabled,
        array $subscriptionIds,
    ): string {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeSetPublishingModeRequestSecure($requestId, $authToken, $publishingEnabled, $subscriptionIds);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeSetPublishingModeInnerBody($body, $requestId, $authToken, $publishingEnabled, $subscriptionIds);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return int[]
     */
    public function decodeSetPublishingModeResponse(BinaryDecoder $decoder): array
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

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

        return $results;
    }

    /**
     * @param int $requestId
     * @param NodeId $authToken
     * @param bool $publishingEnabled
     * @param int[] $subscriptionIds
     */
    private function encodeSetPublishingModeRequestSecure(
        int $requestId,
        NodeId $authToken,
        bool $publishingEnabled,
        array $subscriptionIds,
    ): string {
        $body = new BinaryEncoder();
        $this->writeSetPublishingModeInnerBody($body, $requestId, $authToken, $publishingEnabled, $subscriptionIds);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
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
        $body->writeNodeId(NodeId::numeric(0, 799));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeBoolean($publishingEnabled);

        $body->writeInt32(count($subscriptionIds));
        foreach ($subscriptionIds as $id) {
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
