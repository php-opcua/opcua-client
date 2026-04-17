<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA SetTriggering operation.
 *
 * @see SubscriptionModule::setTriggering()
 */
readonly class SetTriggeringResult implements WireSerializable
{
    /**
     * @param int[] $addResults Status codes for each link addition.
     * @param int[] $removeResults Status codes for each link removal.
     */
    public function __construct(
        public array $addResults,
        public array $removeResults,
    ) {
    }

    /**
     * @return array{add: int[], remove: int[]}
     */
    public function jsonSerialize(): array
    {
        return ['add' => $this->addResults, 'remove' => $this->removeResults];
    }

    /**
     * @param array{add?: int[], remove?: int[]} $data
     * @return static
     */
    public static function fromWireArray(array $data): static
    {
        return new self($data['add'] ?? [], $data['remove'] ?? []);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'SetTriggeringResult';
    }
}
