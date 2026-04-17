<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Protocol\AbstractProtocolService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

class TranslateBrowsePathService extends AbstractProtocolService
{
    /**
     * @param int $requestId
     * @param array<array{startingNodeId: NodeId, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}> $browsePaths
     * @param NodeId $authToken
     * @return string
     */
    public function encodeTranslateRequest(int $requestId, array $browsePaths, NodeId $authToken): string
    {
        $body = new BinaryEncoder();
        $this->writeTranslateInnerBody($body, $requestId, $browsePaths, $authToken);

        return $this->encodeRequestAuto($requestId, $body->getBuffer());
    }

    /**
     * @param BinaryDecoder $decoder
     * @return BrowsePathResult[]
     */
    public function decodeTranslateResponse(BinaryDecoder $decoder): array
    {
        $this->readResponseMetadata($decoder);

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
     * @param BinaryEncoder $body
     * @param int $requestId
     * @param array $browsePaths
     * @param NodeId $authToken
     */
    private function writeTranslateInnerBody(BinaryEncoder $body, int $requestId, array $browsePaths, NodeId $authToken): void
    {
        $body->writeNodeId(NodeId::numeric(0, ServiceTypeId::TRANSLATE_BROWSE_PATHS_REQUEST));

        $this->writeRequestHeader($body, $requestId, $authToken);

        $body->writeInt32(count($browsePaths));

        foreach ($browsePaths as $path) {
            $body->writeNodeId($path['startingNodeId']);

            $elements = $path['relativePath'];
            $body->writeInt32(count($elements));

            foreach ($elements as $element) {
                $body->writeNodeId($element['referenceTypeId'] ?? NodeId::numeric(0, ServiceTypeId::HIERARCHICAL_REFERENCES));
                $body->writeBoolean($element['isInverse'] ?? false);
                $body->writeBoolean($element['includeSubtypes'] ?? true);
                $body->writeQualifiedName($element['targetName']);
            }
        }
    }
}
