<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Result of transferring a single subscription to a new session.
 *
 * @see SubscriptionModule::transferSubscriptions()
 */
readonly class TransferResult implements WireSerializable
{
    /**
     * @param int $statusCode
     * @param int[] $availableSequenceNumbers
     */
    public function __construct(
        public int $statusCode,
        public array $availableSequenceNumbers,
    ) {
    }

    /**
     * @return array{status: int, avail: int[]}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->statusCode, 'avail' => $this->availableSequenceNumbers];
    }

    /**
     * @param array{status?: int, avail?: int[]} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['status'] ?? 0, $data['avail'] ?? []);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'TransferResult';
    }
}
