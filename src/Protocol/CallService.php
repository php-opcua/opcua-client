<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class CallService
{
    /**
     * @param SessionService $session
     */
    public function __construct(private readonly SessionService $session)
    {
    }

    /**
     * @param int $requestId
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @param NodeId $authToken
     */
    public function encodeCallRequest(
        int    $requestId,
        NodeId $objectId,
        NodeId $methodId,
        array  $inputArguments,
        NodeId $authToken,
    ): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeCallRequestSecure($requestId, $objectId, $methodId, $inputArguments, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeCallInnerBody($body, $requestId, $objectId, $methodId, $inputArguments, $authToken);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return array{statusCode: int, inputArgumentResults: int[], outputArguments: Variant[]}
     */
    public function decodeCallResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $resultCount = $decoder->readInt32();

        $statusCode = 0;
        $inputArgumentResults = [];
        $outputArguments = [];

        for ($i = 0; $i < $resultCount; $i++) {
            $statusCode = $decoder->readUInt32();

            $inputArgCount = $decoder->readInt32();
            for ($j = 0; $j < $inputArgCount; $j++) {
                $inputArgumentResults[] = $decoder->readUInt32();
            }

            $diagCount = $decoder->readInt32();
            for ($j = 0; $j < $diagCount; $j++) {
                $this->skipDiagnosticInfo($decoder);
            }

            $outputArgCount = $decoder->readInt32();
            for ($j = 0; $j < $outputArgCount; $j++) {
                $outputArguments[] = $decoder->readVariant();
            }
        }

        $diagCount = $decoder->readInt32();
        for ($i = 0; $i < $diagCount; $i++) {
            $this->skipDiagnosticInfo($decoder);
        }

        return [
            'statusCode' => $statusCode,
            'inputArgumentResults' => $inputArgumentResults,
            'outputArguments' => $outputArguments,
        ];
    }

    /**
     * @param int $requestId
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @param NodeId $authToken
     */
    private function encodeCallRequestSecure(
        int    $requestId,
        NodeId $objectId,
        NodeId $methodId,
        array  $inputArguments,
        NodeId $authToken,
    ): string
    {
        $body = new BinaryEncoder();
        $this->writeCallInnerBody($body, $requestId, $objectId, $methodId, $inputArguments, $authToken);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @param NodeId $authToken
     */
    private function writeCallInnerBody(
        BinaryEncoder $body,
        int           $requestId,
        NodeId        $objectId,
        NodeId        $methodId,
        array         $inputArguments,
        NodeId        $authToken,
    ): void
    {
        $body->writeNodeId(NodeId::numeric(0, 712));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeInt32(1);

        $body->writeNodeId($objectId);
        $body->writeNodeId($methodId);

        $body->writeInt32(count($inputArguments));
        foreach ($inputArguments as $arg) {
            $body->writeVariant($arg);
        }
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
