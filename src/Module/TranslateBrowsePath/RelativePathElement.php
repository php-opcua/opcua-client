<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;

/**
 * One element of a relative browse path (OPC UA RelativePathElement).
 *
 * A null `referenceTypeId` means "follow any hierarchical reference".
 *
 * @see BrowsePath
 */
final readonly class RelativePathElement
{
    public function __construct(
        public QualifiedName $targetName,
        public ?NodeId $referenceTypeId = null,
        public bool $isInverse = false,
        public bool $includeSubtypes = true,
    ) {
    }

    /**
     * Build an element from the associative-array form accepted by the public API.
     *
     * @param array{referenceTypeId?: ?NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName} $element
     */
    public static function fromArray(array $element): self
    {
        return new self(
            targetName: $element['targetName'],
            referenceTypeId: $element['referenceTypeId'] ?? null,
            isInverse: $element['isInverse'] ?? false,
            includeSubtypes: $element['includeSubtypes'] ?? true,
        );
    }
}
