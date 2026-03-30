<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\DynamicCodec;
use PhpOpcua\Client\Encoding\StructureDefinitionParser;
use PhpOpcua\Client\Event\DataTypesDiscovered;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use Throwable;

/**
 * Provides automatic discovery and registration of server-defined structured data types.
 *
 * @see DynamicCodec
 * @see StructureDefinitionParser
 */
trait ManagesTypeDiscoveryTrait
{
    /**
     * Discover server-defined structured data types and register dynamic codecs for them.
     *
     * @param ?int $namespaceIndex Only discover types in this namespace. Null for all non-zero namespaces.
     * @param bool $useCache Whether to use cached discovery results.
     * @return int The number of types successfully discovered and registered.
     *
     * @throws \PhpOpcua\Client\Exception\ConnectionException If the connection is lost.
     * @throws \PhpOpcua\Client\Exception\ServiceException If the server returns an error.
     */
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        $this->ensureConnected();
        $this->logger->info('Discovering data types' . ($namespaceIndex !== null ? " for namespace {$namespaceIndex}" : ''), $this->logContext());

        $cacheKey = $this->buildSimpleCacheKey('dataTypes', (string) ($namespaceIndex ?? 'all'));

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
            $this->logger->info('Restored {count} data type(s) from cache', $this->logContext(['count' => $registered]));

            return $registered;
        }

        $tree = $this->browseRecursive(
            NodeId::numeric(0, ServiceTypeId::BASE_DATA_TYPE),
            BrowseDirection::Forward,
            maxDepth: 10,
            referenceTypeId: NodeId::numeric(0, ServiceTypeId::HAS_SUBTYPE),
            nodeClasses: [NodeClass::DataType],
        );

        $registered = 0;
        $discoveredEntries = [];
        $this->discoverFromTree($tree, $namespaceIndex, $registered, $discoveredEntries);

        if ($useCache && $this->cache !== null && ! empty($discoveredEntries)) {
            $this->cache->set($cacheKey, $discoveredEntries);
        }

        $this->logger->info('Discovered {count} data type(s)', $this->logContext(['count' => $registered]));
        $this->dispatch(fn () => new DataTypesDiscovered($this, $namespaceIndex, $registered));

        return $registered;
    }

    /**
     * Recursively discover data types from a browse tree.
     *
     * @param \PhpOpcua\Client\Types\BrowseNode[] $nodes The browse tree nodes.
     * @param ?int $namespaceIndex Filter by namespace index, or null for all.
     * @param int $registered Counter for registered types (passed by reference).
     * @param array<array{encodingId: NodeId, definition: \PhpOpcua\Client\Types\StructureDefinition}> $discoveredEntries Accumulated entries (passed by reference).
     * @return void
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
     * Discover a single data type by its NodeId.
     *
     * @param NodeId $dataTypeNodeId The data type NodeId.
     * @return ?array{encodingId: NodeId, definition: \PhpOpcua\Client\Types\StructureDefinition}
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

        $dataValue = $this->read($dataTypeNodeId, AttributeId::DataTypeDefinition);
        if (StatusCode::isBad($dataValue->statusCode)) {
            return null;
        }

        $raw = $dataValue->getValue();
        if (! $raw instanceof ExtensionObject || $raw->body === null) {
            return null;
        }

        if ($raw->typeId->namespaceIndex === 0 && $raw->typeId->identifier === 123) {
            return null;
        }

        $bodyDecoder = new BinaryDecoder($raw->body);
        $definition = StructureDefinitionParser::parse($bodyDecoder);

        $this->extensionObjectRepository->register($encodingId, new DynamicCodec($definition));

        return ['encodingId' => $encodingId, 'definition' => $definition];
    }

    /**
     * Find the Default Binary encoding NodeId for a data type.
     *
     * @param NodeId $dataTypeNodeId The data type NodeId.
     * @return ?NodeId The encoding NodeId, or null if not found.
     */
    private function findBinaryEncodingId(NodeId $dataTypeNodeId): ?NodeId
    {
        try {
            $encodingRefs = $this->browse(
                $dataTypeNodeId,
                BrowseDirection::Forward,
                NodeId::numeric(0, ServiceTypeId::HAS_ENCODING),
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
