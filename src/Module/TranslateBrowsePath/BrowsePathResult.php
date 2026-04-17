<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

/**
 * Holds the result of a TranslateBrowsePathsToNodeIds operation for a single browse path.
 */
readonly class BrowsePathResult
{
    /**
     * @param int $statusCode
     * @param BrowsePathTarget[] $targets
     */
    public function __construct(
        public int $statusCode,
        public array $targets,
    ) {
    }
}
