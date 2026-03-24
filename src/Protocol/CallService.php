<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

class CallService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param NodeId $objectId
     * @param NodeId $methodId
     * @param Variant[] $inputArguments
     * @param NodeId $authToken
     */
    public function encodeCallRequest(
        int $requestId,
        NodeId $objectId,
        NodeId $methodId,
        array $inputArguments,
        NodeId $authToken,
    ): string {
        $body = new BinaryEncoder();
        $this->writeCallInnerBody($body, $requestId, $objectId, $methodId, $inputArguments, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return CallResult
     */
    public function decodeCallResponse(BinaryDecoder $decoder): CallResult
    {
        $this->readResponseMetadata($decoder);

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

            $decoder->skipDiagnosticInfoArray();

            $outputArgCount = $decoder->readInt32();
            for ($j = 0; $j < $outputArgCount; $j++) {
                $outputArguments[] = $decoder->readVariant();
            }
        }

        $decoder->skipDiagnosticInfoArray();

        return new CallResult($statusCode, $inputArgumentResults, $outputArguments);
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
        int $requestId,
        NodeId $objectId,
        NodeId $methodId,
        array $inputArguments,
        NodeId $authToken,
    ): void {
        $body->writeNodeId(NodeId::numeric(0, 712));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(1);

        $body->writeNodeId($objectId);
        $body->writeNodeId($methodId);

        $body->writeInt32(count($inputArguments));
        foreach ($inputArguments as $arg) {
            $body->writeVariant($arg);
        }
    }
}
