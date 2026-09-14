<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after every publish response is decoded, regardless of notification content.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::publish()
 */
readonly class PublishResponseReceived
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
        public int $notificationCount,
        public bool $moreNotifications,
    ) {
    }
}
