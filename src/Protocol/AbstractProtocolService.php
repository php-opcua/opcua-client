<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Types\NodeId;

/**
 * Base class for OPC UA protocol services, providing shared encoding and decoding helpers.
 *
 * Eliminates the repeated wrapInMessage(), writeRequestHeader(), and readResponseMetadata()
 * boilerplate that was previously duplicated across all protocol service classes.
 */
abstract class AbstractProtocolService
{
    /**
     * @param SessionService $session
     */
    public function __construct(protected readonly SessionService $session)
    {
    }

    /**
     * Encode a non-secure request: prepends the security header and wraps in a MSG frame.
     *
     * @param int $requestId
     * @param string $innerBodyBytes
     * @return string
     */
    protected function encodeRequest(int $requestId, string $innerBodyBytes): string
    {
        $body = new BinaryEncoder();
        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);
        $body->writeRawBytes($innerBodyBytes);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * Encode a secure request: wraps the inner body via the secure channel.
     *
     * @param string $innerBodyBytes
     * @return string
     */
    protected function encodeRequestSecure(string $innerBodyBytes): string
    {
        return $this->session->getSecureChannel()->buildMessage($innerBodyBytes);
    }

    /**
     * Determine whether to use secure or non-secure encoding and return the result.
     *
     * @param int $requestId
     * @param string $innerBodyBytes
     * @return string
     */
    protected function encodeRequestAuto(int $requestId, string $innerBodyBytes): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeRequestSecure($innerBodyBytes);
        }

        return $this->encodeRequest($requestId, $innerBodyBytes);
    }

    /**
     * Write the OPC UA RequestHeader structure.
     *
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $authToken
     * @param int $timeoutHint
     * @return void
     */
    protected function writeRequestHeader(
        BinaryEncoder $body,
        int $requestId,
        NodeId $authToken,
        int $timeoutHint = 10000,
    ): void {
        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32($timeoutHint);
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::NULL));
        $body->writeByte(0);
    }

    /**
     * Read and discard the response metadata: token, sequence number, request ID, type NodeId, and ResponseHeader.
     *
     * @param BinaryDecoder $decoder
     * @return int The status code from the ResponseHeader.
     */
    protected function readResponseMetadata(BinaryDecoder $decoder): int
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readNodeId();

        return $this->session->readResponseHeader($decoder);
    }

    /**
     * Wrap encoded body bytes in an OPC UA MSG frame with the secure channel ID.
     *
     * @param string $bodyBytes
     * @return string
     */
    protected function wrapInMessage(string $bodyBytes): string
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
