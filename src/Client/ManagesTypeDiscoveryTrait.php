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
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        $this->ensureConnected();
        $this->logger->info('Discovering data types' . ($namespaceIndex !== null ? " for namespace {$namespaceIndex}" : ''));

        $cacheKey = $this->buildSimpleCacheKey('dataTypes', (string)($namespaceIndex ?? 'all'));

        $this->ensureCacheInitialized();
        $cached = ($useCache && $this->cache !== null) ? $this->cache->get($cacheKey) : null;

        if ($cached !== null && is_array($cached)) {
            $registered = 0;
            foreach ($cached as $entry) {
                if ($this->extensionObjectRepository->has($entry['encodingId'])) {
                    continue;
                }
                $this->extensionObjectRepository->register($entry['encodingId'], new DynamicCodec($entry['definition']));
                $registered++;
            }
            $this->logger->info('Restored {count} data type(s) from cache', ['count' => $registered]);
            return $registered;
        }

        $tree = $this->browseRecursive(
            NodeId::numeric(0, 22),
            BrowseDirection::Forward,
            maxDepth: 10,
            referenceTypeId: NodeId::numeric(0, 45),
            nodeClasses: [NodeClass::DataType],
        );

        $registered = 0;
        $discoveredEntries = [];
        $this->discoverFromTree($tree, $namespaceIndex, $registered, $discoveredEntries);

        if ($useCache && $this->cache !== null && !empty($discoveredEntries)) {
            $this->cache->set($cacheKey, $discoveredEntries);
        }

        $this->logger->info('Discovered {count} data type(s)', ['count' => $registered]);
        return $registered;
    }

    /**
     * @param \Gianfriaur\OpcuaPhpClient\Types\BrowseNode[] $nodes
     * @param ?int $namespaceIndex
     * @param int $registered
     * @param array<array{encodingId: NodeId, definition: \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition}> $discoveredEntries
     */
    private function discoverFromTree(array $nodes, ?int $namespaceIndex, int &$registered, array &$discoveredEntries): void
    {
        foreach ($nodes as $node) {
            $nodeId = $node->reference->nodeId;

            if ($nodeId->namespaceIndex !== 0) {
                if ($namespaceIndex === null || $nodeId->namespaceIndex === $namespaceIndex) {
                    try {
                        $entry = $this->discoverSingleDataType($nodeId);
                        if ($entry !== null) {
                            $registered++;
                            $discoveredEntries[] = $entry;
                        }
                    } catch (Throwable) {
                    }
                }
            }

            if ($node->hasChildren()) {
                $this->discoverFromTree($node->getChildren(), $namespaceIndex, $registered, $discoveredEntries);
            }
        }
    }

    /**
     * @param NodeId $dataTypeNodeId
     * @return ?array{encodingId: NodeId, definition: \Gianfriaur\OpcuaPhpClient\Types\StructureDefinition}
     */
    private function discoverSingleDataType(NodeId $dataTypeNodeId): ?array
    {
        $encodingId = $this->findBinaryEncodingId($dataTypeNodeId);
        if ($encodingId === null) {
            return null;
        }

        if ($this->extensionObjectRepository->has($encodingId)) {
            return null;
        }

        $dv = $this->read($dataTypeNodeId, 26);
        if (StatusCode::isBad($dv->statusCode)) {
            return null;
        }

        $raw = $dv->getValue();
        if (!is_array($raw) || !isset($raw['body']) || !is_string($raw['body'])) {
            return null;
        }

        if (isset($raw['typeId']) && $raw['typeId'] instanceof NodeId) {
            if ($raw['typeId']->namespaceIndex === 0 && $raw['typeId']->identifier === 123) {
                return null;
            }
        }

        $bodyDecoder = new BinaryDecoder($raw['body']);
        $definition = StructureDefinitionParser::parse($bodyDecoder);

        $this->extensionObjectRepository->register($encodingId, new DynamicCodec($definition));

        return ['encodingId' => $encodingId, 'definition' => $definition];
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
