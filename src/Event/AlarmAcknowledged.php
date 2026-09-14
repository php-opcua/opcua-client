<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched when an alarm's AckedState transitions to true.
 *
 * `republished` is true when the notification was retransmitted by republish()
 * rather than delivered by publish().
 *
 * @see AlarmEventReceived
 */
readonly class AlarmAcknowledged
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $clientHandle,
        public ?string $sourceName = null,
        public bool $republished = false,
    ) {
    }
}
