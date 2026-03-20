<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec;
use Gianfriaur\OpcuaPhpClient\Encoding\StructureDefinitionParser;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Throwable;

/**
 * Provides automatic discovery and registration of server-defined structured data types.
 *
 * @see \Gianfriaur\OpcuaPhpClient\Encoding\DynamicCodec
 * @see \Gianfriaur\OpcuaPhpClient\Encoding\StructureDefinitionParser
 */
trait ManagesTypeDiscoveryTrait
{
    /**
     * Discover server-defined structured data types and register dynamic codecs for them.
     *
     * @param ?int $namespaceIndex Only discover types in this namespace. Null for all non-zero namespaces.
     * @return int The number of types successfully discovered and registered.
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ServiceException If the server returns an error.
     */
    public function discoverDataTypes(?int $namespaceIndex = null): int
    {
        $this->ensureConnected();

        $tree = $this->browseRecursive(
            NodeId::numeric(0, 22),
            BrowseDirection::Forward,
            maxDepth: 10,
            referenceTypeId: NodeId::numeric(0, 45),
            nodeClasses: [NodeClass::DataType],
        );

        $registered = 0;
        $this->discoverFromTree($tree, $namespaceIndex, $registered);

        return $registered;
    }

    /**
     * @param \Gianfriaur\OpcuaPhpClient\Types\BrowseNode[] $nodes
     * @param ?int $namespaceIndex
     * @param int $registered
     */
    private function discoverFromTree(array $nodes, ?int $namespaceIndex, int &$registered): void
    {
        foreach ($nodes as $node) {
            $nodeId = $node->reference->nodeId;

            if ($nodeId->namespaceIndex !== 0) {
                if ($namespaceIndex === null || $nodeId->namespaceIndex === $namespaceIndex) {
                    try {
                        $registered += $this->discoverSingleDataType($nodeId) ? 1 : 0;
                    } catch (Throwable) {
                    }
                }
            }

            if ($node->hasChildren()) {
                $this->discoverFromTree($node->getChildren(), $namespaceIndex, $registered);
            }
        }
    }

    /**
     * @param NodeId $dataTypeNodeId
     * @return bool
     */
    private function discoverSingleDataType(NodeId $dataTypeNodeId): bool
    {
        $encodingId = $this->findBinaryEncodingId($dataTypeNodeId);
        if ($encodingId === null) {
            return false;
        }

        if ($this->extensionObjectRepository->has($encodingId)) {
            return false;
        }

        $dv = $this->read($dataTypeNodeId, 26);
        if (StatusCode::isBad($dv->statusCode)) {
            return false;
        }

        $raw = $dv->getValue();
        if (!is_array($raw) || !isset($raw['body']) || !is_string($raw['body'])) {
            return false;
        }

        if (isset($raw['typeId']) && $raw['typeId'] instanceof NodeId) {
            if ($raw['typeId']->namespaceIndex === 0 && $raw['typeId']->identifier === 123) {
                return false;
            }
        }

        $bodyDecoder = new BinaryDecoder($raw['body']);
        $definition = StructureDefinitionParser::parse($bodyDecoder);

        $this->extensionObjectRepository->register($encodingId, new DynamicCodec($definition));

        return true;
    }

    /**
     * @param NodeId $dataTypeNodeId
     * @return ?NodeId
     */
    private function findBinaryEncodingId(NodeId $dataTypeNodeId): ?NodeId
    {
        try {
            $encodingRefs = $this->browse(
                $dataTypeNodeId,
                BrowseDirection::Forward,
                NodeId::numeric(0, 38),
            );

            foreach ($encodingRefs as $ref) {
                if ($ref->browseName->name === 'Default Binary') {
                    return $ref->nodeId;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
