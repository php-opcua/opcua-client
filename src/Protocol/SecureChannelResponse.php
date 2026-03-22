<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;

/**
 * OPC UA OPN (OpenSecureChannel) response decoding.
 */
class SecureChannelResponse
{
    /**
     * @param int $secureChannelId
     * @param int $tokenId
     * @param int $revisedLifetime
     */
    public function __construct(
        private readonly int $secureChannelId,
        private readonly int $tokenId,
        private readonly int $revisedLifetime,
    )
    {
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public static function decode(BinaryDecoder $decoder): self
    {
        $decoder->readString();
        $decoder->readByteString();
        $decoder->readByteString();

        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $decoder->readInt64();
        $decoder->readUInt32();
        $statusCode = $decoder->readUInt32();
        $decoder->readByte();
        $decoder->readInt32();
        $decoder->readNodeId();
        $decoder->readByte();

        $decoder->readUInt32();
        $secureChannelId = $decoder->readUInt32();
        $tokenId = $decoder->readUInt32();
        $decoder->readInt64();
        $revisedLifetime = $decoder->readUInt32();
        $decoder->readByteString();

        return new self($secureChannelId, $tokenId, (int)$revisedLifetime);
    }

    public function getSecureChannelId(): int
    {
        return $this->secureChannelId;
    }

    public function getTokenId(): int
    {
        return $this->tokenId;
    }

    public function getRevisedLifetime(): int
    {
        return $this->revisedLifetime;
    }
}
