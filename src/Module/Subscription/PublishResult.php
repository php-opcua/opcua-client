<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Subscription;

/**
 * Holds the result of an OPC UA Publish operation containing subscription notifications.
 *
 * @see SubscriptionModule::publish()
 */
readonly class PublishResult
{
    /**
     * @param int $subscriptionId
     * @param int $sequenceNumber
     * @param bool $moreNotifications
     * @param array $notifications
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
}
