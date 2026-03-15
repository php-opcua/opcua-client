<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

class SecureChannelRequest
{
    /**
     * @param int $secureChannelId
     */
    public function encode(int $secureChannelId = 0): string
    {
        $body = new BinaryEncoder();

        $body->writeString('http://opcfoundation.org/UA/SecurityPolicy#None');
        $body->writeByteString(null);
        $body->writeByteString(null);

        $body->writeUInt32(1);
        $body->writeUInt32(1);

        $body->writeNodeId(NodeId::numeric(0, 446));

        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeInt64(0);
        $body->writeUInt32(1);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeUInt32(0);
        $body->writeUInt32(0);
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
