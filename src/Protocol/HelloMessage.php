<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;

/**
 * OPC UA HEL (Hello) message for TCP connection handshake.
 */
class HelloMessage
{
    public const DEFAULT_BUFFER_SIZE = 65535;

    /**
     * @param int $protocolVersion
     * @param int $receiveBufferSize
     * @param int $sendBufferSize
     * @param int $maxMessageSize
     * @param int $maxChunkCount
     * @param string $endpointUrl
     */
    public function __construct(
        private readonly int $protocolVersion = 0,
        private readonly int $receiveBufferSize = self::DEFAULT_BUFFER_SIZE,
        private readonly int $sendBufferSize = self::DEFAULT_BUFFER_SIZE,
        private readonly int $maxMessageSize = 0,
        private readonly int $maxChunkCount = 0,
        private readonly string $endpointUrl = '',
    ) {
    }

    public function encode(): string
    {
        $body = new BinaryEncoder();
        $body->writeUInt32($this->protocolVersion);
        $body->writeUInt32($this->receiveBufferSize);
        $body->writeUInt32($this->sendBufferSize);
        $body->writeUInt32($this->maxMessageSize);
        $body->writeUInt32($this->maxChunkCount);
        $body->writeString($this->endpointUrl);

        $bodyBytes = $body->getBuffer();
        $totalSize = MessageHeader::HEADER_SIZE + strlen($bodyBytes);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('HEL', 'F', $totalSize);
        $header->encode($encoder);
        $encoder->writeRawBytes($bodyBytes);

        return $encoder->getBuffer();
    }

    /**
     * @param BinaryDecoder $decoder
     */
    public static function decode(BinaryDecoder $decoder): self
    {
        $protocolVersion = $decoder->readUInt32();
        $receiveBufferSize = $decoder->readUInt32();
        $sendBufferSize = $decoder->readUInt32();
        $maxMessageSize = $decoder->readUInt32();
        $maxChunkCount = $decoder->readUInt32();
        $endpointUrl = $decoder->readString() ?? '';

        return new self(
            $protocolVersion,
            $receiveBufferSize,
            $sendBufferSize,
            $maxMessageSize,
            $maxChunkCount,
            $endpointUrl,
        );
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function getReceiveBufferSize(): int
    {
        return $this->receiveBufferSize;
    }

    public function getSendBufferSize(): int
    {
        return $this->sendBufferSize;
    }
}
