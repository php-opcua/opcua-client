<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * A data-change notification for one monitored item, delivered in a Publish response.
 *
 * @see PublishResult::$notifications
 */
final readonly class DataChangeNotification implements WireSerializable
{
    public function __construct(
        public int $clientHandle,
        public DataValue $dataValue,
    ) {
    }

    /**
     * @return array{handle: int, dv: DataValue}
     */
    public function jsonSerialize(): array
    {
        return ['handle' => $this->clientHandle, 'dv' => $this->dataValue];
    }

    /**
     * @param array<string, mixed> $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $handle = $data['handle'] ?? null;
        $dataValue = $data['dv'] ?? null;
        if (! is_int($handle) || ! $dataValue instanceof DataValue) {
            throw new EncodingException('DataChangeNotification wire payload: expected int "handle" and decoded DataValue "dv".');
        }

        return new self($handle, $dataValue);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'DataChangeNotification';
    }
}
