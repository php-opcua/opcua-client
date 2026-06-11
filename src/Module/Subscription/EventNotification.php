<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireSerializable;

/**
 * An event notification for one monitored item, delivered in a Publish response.
 *
 * @see PublishResult::$notifications
 */
final readonly class EventNotification implements WireSerializable
{
    /**
     * @param Variant[] $eventFields
     */
    public function __construct(
        public int $clientHandle,
        public array $eventFields,
    ) {
    }

    /**
     * @return array{handle: int, fields: Variant[]}
     */
    public function jsonSerialize(): array
    {
        return ['handle' => $this->clientHandle, 'fields' => $this->eventFields];
    }

    /**
     * @param array<string, mixed> $data
     * @return static
     * @throws EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $handle = $data['handle'] ?? null;
        $rawFields = $data['fields'] ?? [];
        if (! is_int($handle) || ! is_array($rawFields)) {
            throw new EncodingException('EventNotification wire payload: expected int "handle" and array "fields".');
        }

        $fields = [];
        foreach ($rawFields as $field) {
            if (! $field instanceof Variant) {
                throw new EncodingException('EventNotification wire payload: "fields" must contain decoded Variant instances.');
            }
            $fields[] = $field;
        }

        return new self($handle, $fields);
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'EventNotification';
    }
}
