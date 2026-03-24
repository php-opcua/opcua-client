<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\BinaryEncoder;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathTarget;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

class TranslateBrowsePathService
{
    public function __construct(private readonly SessionService $session)
    {
    }

    /**
     * @param int $requestId
     * @param array<array{startingNodeId: NodeId, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}> $browsePaths
     * @param NodeId $authToken
     * @return string
     */
    public function encodeTranslateRequest(int $requestId, array $browsePaths, NodeId $authToken): string
    {
        $secureChannel = $this->session->getSecureChannel();
        if ($secureChannel !== null && $secureChannel->isSecurityActive()) {
            return $this->encodeTranslateRequestSecure($requestId, $browsePaths, $authToken);
        }

        $body = new BinaryEncoder();

        $body->writeUInt32($this->session->getTokenId());
        $body->writeUInt32($this->session->getNextSequenceNumber());
        $body->writeUInt32($requestId);

        $this->writeTranslateInnerBody($body, $requestId, $browsePaths, $authToken);

        return $this->wrapInMessage($body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return BrowsePathResult[]
     */
    public function decodeTranslateResponse(BinaryDecoder $decoder): array
    {
        $decoder->readUInt32();
        $decoder->readUInt32();
        $decoder->readUInt32();

        $decoder->readNodeId();

        $this->session->readResponseHeader($decoder);

        $resultCount = $decoder->readInt32();
        $results = [];

        for ($i = 0; $i < $resultCount; $i++) {
            $statusCode = $decoder->readUInt32();

            $targetCount = $decoder->readInt32();
            $targets = [];

            for ($j = 0; $j < $targetCount; $j++) {
                $targetId = $decoder->readExpandedNodeId();
                $remainingPathIndex = $decoder->readUInt32();

                $targets[] = new BrowsePathTarget($targetId, $remainingPathIndex);
            }

            $results[] = new BrowsePathResult($statusCode, $targets);
        }

        $decoder->skipDiagnosticInfoArray();

        return $results;
    }

    /**
     * @param int $requestId
     * @param array $browsePaths
     * @param NodeId $authToken
     * @return string
     */
    private function encodeTranslateRequestSecure(int $requestId, array $browsePaths, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeTranslateInnerBody($body, $requestId, $browsePaths, $authToken);

        return $this->session->getSecureChannel()->buildMessage($body->getBuffer());
    }

    /**
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array $browsePaths
     * @param NodeId $authToken
     */
    private function writeTranslateInnerBody(BinaryEncoder $body, int $requestId, array $browsePaths, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, 554));

        $body->writeNodeId($authToken);
        $body->writeInt64(0);
        $body->writeUInt32($requestId);
        $body->writeUInt32(0);
        $body->writeString(null);
        $body->writeUInt32(10000);
        $body->writeNodeId(NodeId::numeric(0, 0));
        $body->writeByte(0);

        $body->writeInt32(count($browsePaths));

        foreach ($browsePaths as $path) {
            $body->writeNodeId($path['startingNodeId']);

            $elements = $path['relativePath'];
            $body->writeInt32(count($elements));

            foreach ($elements as $element) {
                $body->writeNodeId($element['referenceTypeId'] ?? NodeId::numeric(0, 33));
                $body->writeBoolean($element['isInverse'] ?? false);
                $body->writeBoolean($element['includeSubtypes'] ?? true);
                $body->writeQualifiedName($element['targetName']);
            }
        }
    }

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
