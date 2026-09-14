<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched when an alarm transitions to the active state.
 *
 * Deduced from the ActiveState field being true in the event notification.
 *
 * `republished` is true when the notification was retransmitted by republish()
 * rather than delivered by publish().
 *
 * @see AlarmEventReceived
 */
readonly class AlarmActivated
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $clientHandle,
        public ?string $sourceName = null,
        public ?int $severity = null,
        public ?string $message = null,
        public bool $republished = false,
    ) {
    }
}
