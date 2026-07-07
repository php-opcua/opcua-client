<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\NodeManagement;

use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\Variant;

/**
 * Specification of a single node for an AddNodes request: placement
 * (parent, reference, requested id) plus the class-specific attributes.
 *
 * @see NodeManagementModule::addNodes()
 */
final readonly class AddNodeItem
{
    /**
     * @param int[] $arrayDimensions
     */
    public function __construct(
        public NodeId $parentNodeId,
        public NodeId $referenceTypeId,
        public NodeId $requestedNewNodeId,
        public QualifiedName $browseName,
        public NodeClass $nodeClass,
        public NodeId $typeDefinition,
        public ?string $displayName = null,
        public ?string $description = null,
        public int $writeMask = 0,
        public int $userWriteMask = 0,
        public ?Variant $value = null,
        public ?NodeId $dataType = null,
        public int $valueRank = -1,
        public array $arrayDimensions = [],
        public int $accessLevel = 1,
        public int $userAccessLevel = 1,
        public float $minimumSamplingInterval = 0.0,
        public bool $historizing = false,
        public bool $executable = true,
        public bool $userExecutable = true,
        public bool $isAbstract = false,
        public bool $symmetric = false,
        public ?string $inverseName = null,
        public bool $containsNoLoops = false,
        public int $eventNotifier = 0,
    ) {
    }

    /**
     * Build an item from the associative-array form accepted by the public API.
     *
     * @param array{
     *     parentNodeId: NodeId|string,
     *     referenceTypeId: NodeId|string,
     *     requestedNewNodeId: NodeId|string,
     *     browseName: QualifiedName,
     *     nodeClass: NodeClass,
     *     typeDefinition: NodeId|string,
     *     displayName?: ?string,
     *     description?: ?string,
     *     writeMask?: int,
     *     userWriteMask?: int,
     *     value?: ?Variant,
     *     dataType?: ?NodeId,
     *     valueRank?: int,
     *     arrayDimensions?: int[],
     *     accessLevel?: int,
     *     userAccessLevel?: int,
     *     minimumSamplingInterval?: float,
     *     historizing?: bool,
     *     executable?: bool,
     *     userExecutable?: bool,
     *     isAbstract?: bool,
     *     symmetric?: bool,
     *     inverseName?: ?string,
     *     containsNoLoops?: bool,
     *     eventNotifier?: int,
     * } $item
     */
    public static function fromArray(array $item): self
    {
        return new self(
            parentNodeId: self::toNodeId($item['parentNodeId']),
            referenceTypeId: self::toNodeId($item['referenceTypeId']),
            requestedNewNodeId: self::toNodeId($item['requestedNewNodeId']),
            browseName: $item['browseName'],
            nodeClass: $item['nodeClass'],
            typeDefinition: self::toNodeId($item['typeDefinition']),
            displayName: $item['displayName'] ?? null,
            description: $item['description'] ?? null,
            writeMask: $item['writeMask'] ?? 0,
            userWriteMask: $item['userWriteMask'] ?? 0,
            value: $item['value'] ?? null,
            dataType: $item['dataType'] ?? null,
            valueRank: $item['valueRank'] ?? -1,
            arrayDimensions: $item['arrayDimensions'] ?? [],
            accessLevel: $item['accessLevel'] ?? 1,
            userAccessLevel: $item['userAccessLevel'] ?? 1,
            minimumSamplingInterval: $item['minimumSamplingInterval'] ?? 0.0,
            historizing: $item['historizing'] ?? false,
            executable: $item['executable'] ?? true,
            userExecutable: $item['userExecutable'] ?? true,
            isAbstract: $item['isAbstract'] ?? false,
            symmetric: $item['symmetric'] ?? false,
            inverseName: $item['inverseName'] ?? null,
            containsNoLoops: $item['containsNoLoops'] ?? false,
            eventNotifier: $item['eventNotifier'] ?? 0,
        );
    }

    private static function toNodeId(NodeId|string $nodeId): NodeId
    {
        return is_string($nodeId) ? NodeId::parse($nodeId) : $nodeId;
    }
}
