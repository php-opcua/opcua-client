<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Types\NodeId;

/**
 * OPC UA OPN (OpenSecureChannel) request encoding.
 */
class SecureChannelRequest
{
    public const REQUEST_TYPE_ISSUE = 0;

    public const REQUEST_TYPE_RENEW = 1;

    /**
     * @param int $secureChannelId
     * @param int $requestType
     * @param int $sequenceNumber
     */
    public function encode(int $secureChannelId = 0, int $requestType = self::REQUEST_TYPE_ISSUE, int $sequenceNumber = 1): string
    {
        $body = new BinaryEncoder();

        $body->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
        $body->writeByteString(null);
        $body->writeByteString(null);

        $body->writeUInt32($sequenceNumber);
        $body->writeUInt32(1);

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::OPEN_SECURE_CHANNEL_REQUEST));

        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $body->writeDateTime(new \DateTimeImmutable());
        $body->writeUInt32(1);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $body->writeByte(0);

        $body->writeUInt32(0);
        $body->writeUInt32($requestType);
        $body->writeUInt32(1);
        $body->writeByteString(null);
        $body->writeUInt32(3600000);

        $bodyBytes = $body->getBuffer();
        $totalSize = MessageHeader::HEADER_SIZE + 4 + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('OPN', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeUInt32($secureChannelId);
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }
}
