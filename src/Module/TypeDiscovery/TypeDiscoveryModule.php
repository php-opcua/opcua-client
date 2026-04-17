<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TypeDiscovery;

use PhpOpcua\Client\Encoding\BinaryDecoder;
use PhpOpcua\Client\Encoding\DynamicCodec;
use PhpOpcua\Client\Encoding\ExtensionObjectCodec;
use PhpOpcua\Client\Encoding\StructureDefinitionParser;
use PhpOpcua\Client\Event\DataTypesDiscovered;
use PhpOpcua\Client\Module\Browse\BrowseModule;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\ServiceModule;
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
class TypeDiscoveryModule extends ServiceModule
{
    /**
     * @return array<class-string<ServiceModule>>
     */
    public function requires(): array
    {
        return [ReadWriteModule::class, BrowseModule::class];
    }

    public function register(): void
    {
        $this->client->registerMethod('discoverDataTypes', $this->discoverDataTypes(...));
        $this->client->registerMethod('registerTypeCodec', $this->registerTypeCodec(...));
    }

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
        $this->kernel->ensureConnected();
        $this->kernel->log()->info('Discovering data types' . ($namespaceIndex !== null ? " for namespace {$namespaceIndex}" : ''), $this->kernel->logContext());

        $cacheKey = $this->kernel->buildSimpleCacheKey('dataTypes', (string) ($namespaceIndex ?? 'all'));

        $this->kernel->ensureCacheInitialized();
        $cache = $this->kernel->getCache();
        $cached = ($useCache && $cache !== null) ? $cache->get($cacheKey) : null;

        if ($cached !== null && is_array($cached)) {
            $registered = 0;
            foreach ($cached as $entry) {
                if ($this->kernel->getExtensionObjectRepository()->has($entry['encodingId'])) {
                    continue;
                }
                $this->kernel->getExtensionObjectRepository()->register($entry['encodingId'], new DynamicCodec($entry['definition']));
                $registered++;
            }
            $this->kernel->log()->info('Restored {count} data type(s) from cache', $this->kernel->logContext(['count' => $registered]));

            return $registered;
        }

        $tree = $this->client->browseRecursive(
            NodeId::numeric(0, ServiceTypeId::BASE_DATA_TYPE),
            BrowseDirection::Forward,
            maxDepth: 10,
            referenceTypeId: NodeId::numeric(0, ServiceTypeId::HAS_SUBTYPE),
            nodeClasses: [NodeClass::DataType],
        );

        $registered = 0;
        $discoveredEntries = [];
        $this->discoverFromTree($tree, $namespaceIndex, $registered, $discoveredEntries);

        if ($useCache && $cache !== null && ! empty($discoveredEntries)) {
            $cache->set($cacheKey, $discoveredEntries);
        }

        $this->kernel->log()->info('Discovered {count} data type(s)', $this->kernel->logContext(['count' => $registered]));
        $this->kernel->dispatch(fn () => new DataTypesDiscovered($this->client, $namespaceIndex, $registered));

        return $registered;
    }

    /**
     * Register a custom ExtensionObject codec for a specific type NodeId.
     *
     * @param NodeId $typeId The encoding NodeId for the ExtensionObject.
     * @param class-string<ExtensionObjectCodec>|ExtensionObjectCodec $codec The codec instance or class name.
     * @return void
     */
    public function registerTypeCodec(NodeId $typeId, string|ExtensionObjectCodec $codec): void
    {
        $this->kernel->getExtensionObjectRepository()->register($typeId, $codec);
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

        if ($this->kernel->getExtensionObjectRepository()->has($encodingId)) {
            return null;
        }

        $dataValue = $this->client->read($dataTypeNodeId, AttributeId::DataTypeDefinition);
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

        $this->kernel->getExtensionObjectRepository()->register($encodingId, new DynamicCodec($definition));

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
            $encodingRefs = $this->client->browse(
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
