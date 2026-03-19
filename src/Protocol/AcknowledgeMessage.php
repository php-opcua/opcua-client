<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;

class AcknowledgeMessage
{
    /**
     * @param int $protocolVersion
     * @param int $receiveBufferSize
     * @param int $sendBufferSize
     * @param int $maxMessageSize
     * @param int $maxChunkCount
     */
    public function __construct(
        private readonly int $protocolVersion,
        private readonly int $receiveBufferSize,
        private readonly int $sendBufferSize,
        private readonly int $maxMessageSize,
        private readonly int $maxChunkCount,
    )
    {
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public static function decode(BinaryDecoder $decoder): self
    {
        return new self(
            $decoder->readUInt32(),
            $decoder->readUInt32(),
            $decoder->readUInt32(),
            $decoder->readUInt32(),
            $decoder->readUInt32(),
        );
    }

    public function getProtocolVersion(): int
    {
        return $this->protocolVersion;
    }

    public function getReceiveBufferSize(): int
    {
        return $this->receiveBufferSize;
    }

    public function getSendBufferSize(): int
    {
        return $this->sendBufferSize;
    }

    public function getMaxMessageSize(): int
    {
        return $this->maxMessageSize;
    }

    public function getMaxChunkCount(): int
    {
        return $this->maxChunkCount;
    }
}
