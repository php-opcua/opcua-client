<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

use PhpOpcua\Client\Wire\WireSerializable;

/**
 * Holds the result of an OPC UA Publish operation containing subscription notifications.
 *
 * @see SubscriptionModule::publish()
 */
final readonly class PublishResult implements WireSerializable
{
    /**
     * @param int $subscriptionId
     * @param int $sequenceNumber
     * @param bool $moreNotifications
     * @param array<int, DataChangeNotification|EventNotification> $notifications
     * @param int[] $availableSequenceNumbers
     */
    public function __construct(
        public int $subscriptionId,
        public int $sequenceNumber,
        public bool $moreNotifications,
        public array $notifications,
        public array $availableSequenceNumbers,
    ) {
    }

    /**
     * @return array{subId: int, seq: int, more: bool, notif: array<int, DataChangeNotification|EventNotification>, avail: int[]}
     */
    public function jsonSerialize(): array
    {
        return [
            'subId' => $this->subscriptionId,
            'seq' => $this->sequenceNumber,
            'more' => $this->moreNotifications,
            'notif' => $this->notifications,
            'avail' => $this->availableSequenceNumbers,
        ];
    }

    /**
     * @param array{subId?: int, seq?: int, more?: bool, notif?: array<int, mixed>, avail?: int[]} $data
     * @return static
     * @throws \PhpOpcua\Client\Exception\EncodingException
     */
    public static function fromWireArray(array $data): static
    {
        $notifications = [];
        foreach ($data['notif'] ?? [] as $notification) {
            if (! $notification instanceof DataChangeNotification && ! $notification instanceof EventNotification) {
                throw new \PhpOpcua\Client\Exception\EncodingException('PublishResult wire payload: "notif" must contain decoded notification instances.');
            }
            $notifications[] = $notification;
        }

        return new self(
            $data['subId'] ?? 0,
            $data['seq'] ?? 0,
            $data['more'] ?? false,
            $notifications,
            $data['avail'] ?? [],
        );
    }

    /**
     * @return string
     */
    public static function wireTypeId(): string
    {
        return 'PublishResult';
    }
}
