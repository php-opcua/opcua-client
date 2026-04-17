<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Types\NodeId;

/**
 * Represents a single target node resolved from a browse path translation.
 *
 * @see BrowsePathResult
 */
readonly class BrowsePathTarget
{
    /**
     * @param NodeId $targetId
     * @param int $remainingPathIndex
     */
    public function __construct(
        public NodeId $targetId,
        public int $remainingPathIndex,
    ) {
    }
}
