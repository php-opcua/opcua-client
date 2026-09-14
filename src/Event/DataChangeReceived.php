<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\DataValue;

/**
 * Dispatched for each data change notification received from a publish response.
 *
 * `republished` is true when the notification was retransmitted by republish()
 * rather than delivered by publish().
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::publish()
 */
readonly class DataChangeReceived
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $sequenceNumber,
        public int $clientHandle,
        public DataValue $dataValue,
        public bool $republished = false,
    ) {
    }
}
