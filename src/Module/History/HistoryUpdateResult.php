<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\History;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Result of a single HistoryUpdate operation (Part 11 §6.9).
 *
 * @see https://reference.opcfoundation.org/Core/Part11/v105/docs/6.9
 */
readonly class HistoryUpdateResult implements WireSerializable
{
    /**
     * @param int $statusCode Overall status code for the operation.
     * @param int[] $operationResults Per-entry status codes (one per DataValue/timestamp/event).
     */
    public function __construct(
        public int $statusCode,
        public array $operationResults = [],
    ) {
    }

    /**
     * @return array{status: int, ops: int[]}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->statusCode, 'ops' => $this->operationResults];
    }

    /**
     * @param array{status?: int, ops?: int[]} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['status'] ?? 0, $data['ops'] ?? []);
    }

    public static function wireTypeId(): string
    {
        return 'HistoryUpdateResult';
    }
}
