<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\Variant;

/**
 * Dispatched for each event notification received from a publish response.
 *
 * `republished` is true when the notification was retransmitted by republish()
 * rather than delivered by publish().
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::publish()
 */
readonly class EventNotificationReceived
{
    /**
     * @param OpcUaClientInterface $client
     * @param int $subscriptionId
     * @param int $sequenceNumber
     * @param int $clientHandle
     * @param Variant[] $eventFields
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
        public int $clientHandle,
        public array $eventFields,
        public bool $republished = false,
    ) {
    }
}
