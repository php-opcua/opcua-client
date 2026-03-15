<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;

class MessageHeader
{
    public const HEADER_SIZE = 8;

    /**
     * @param string $messageType
     * @param string $chunkType
     * @param int $messageSize
     */
    public function __construct(
        private readonly string $messageType,
        private readonly string $chunkType,
        private readonly int $messageSize,
    ) {
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function getChunkType(): string
    {
        return $this->chunkType;
    }

    public function getMessageSize(): int
    {
        return $this->messageSize;
    }

    /**
     * @param BinaryEncoder $encoder
     */
    public function encode(BinaryEncoder $encoder): void
    {
        $encoder->writeRawBytes(substr($this->messageType, 0, 3));
        $encoder->writeRawBytes($this->chunkType);
        $encoder->writeUInt32($this->messageSize);
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public static function decode(BinaryDecoder $decoder): self
    {
        $messageType = $decoder->readRawBytes(3);
        $chunkType = $decoder->readRawBytes(1);
        $messageSize = $decoder->readUInt32();

        return new self($messageType, $chunkType, $messageSize);
    }
}
