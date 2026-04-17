<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Browse;

use PhpOpcua\Client\Types\ReferenceDescription;

/**
 * Holds the result of a browse operation, including references and an optional continuation point.
 */
readonly class BrowseResultSet
{
    /**
     * @param ReferenceDescription[] $references
     * @param ?string $continuationPoint
     */
    public function __construct(
        public array $references,
        public ?string $continuationPoint,
    ) {
    }
}
