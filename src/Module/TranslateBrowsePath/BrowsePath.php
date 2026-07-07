<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

/**
 * A browse path to translate: a starting node plus a sequence of relative
 * path elements (OPC UA BrowsePath).
 *
 * @see TranslateBrowsePathModule::translateBrowsePaths()
 */
final readonly class BrowsePath
{
    /**
     * @param RelativePathElement[] $relativePath
     */
    public function __construct(
        public NodeId $startingNodeId,
        public array $relativePath,
    ) {
    }

    /**
     * Build a browse path from the associative-array form accepted by the public API.
     *
     * @param array{startingNodeId: NodeId|string, relativePath: array<array{referenceTypeId?: ?NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>} $path
     */
    public static function fromArray(array $path): self
    {
        $startingNodeId = $path['startingNodeId'];

        return new self(
            startingNodeId: is_string($startingNodeId) ? NodeId::parse($startingNodeId) : $startingNodeId,
            relativePath: array_map(
                static fn (array $element): RelativePathElement => RelativePathElement::fromArray($element),
                $path['relativePath'],
            ),
        );
    }
}
