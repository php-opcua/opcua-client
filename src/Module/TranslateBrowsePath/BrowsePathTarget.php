<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\TranslateBrowsePath;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Represents a single target node resolved from a browse path translation.
 *
 * @see BrowsePathResult
 */
readonly class BrowsePathTarget implements WireSerializable
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

    /**
     * @return array{target: NodeId, remaining: int}
     */
    public function jsonSerialize(): array
    {
        return ['target' => $this->targetId, 'remaining' => $this->remainingPathIndex];
    }

    /**
     * @param array{target?: mixed, remaining?: int} $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        if (! isset($data['target']) || ! $data['target'] instanceof NodeId) {
            throw new EncodingException('BrowsePathTarget wire payload: "target" must be a decoded NodeId instance.');
        }

        return new self($data['target'], $data['remaining'] ?? 0);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'BrowsePathTarget';
    }
}
