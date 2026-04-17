<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of a TranslateBrowsePathsToNodeIds operation for a single browse path.
 */
readonly class BrowsePathResult implements WireSerializable
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

    /**
     * @return array{status: int, targets: BrowsePathTarget[]}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->statusCode, 'targets' => $this->targets];
    }

    /**
     * @param array{status?: int, targets?: BrowsePathTarget[]} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['status'] ?? 0, $data['targets'] ?? []);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'BrowsePathResult';
    }
}
