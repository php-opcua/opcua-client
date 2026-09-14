<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched when an event notification originates from a LimitAlarm type.
 *
 * The limit state indicates which threshold was crossed (HighHigh, High, Low, LowLow).
 *
 * `republished` is true when the notification was retransmitted by republish()
 * rather than delivered by publish().
 *
 * @see AlarmEventReceived
 */
readonly class LimitAlarmExceeded
{
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $clientHandle,
        public ?string $sourceName = null,
        public ?string $limitState = null,
        public ?int $severity = null,
        public bool $republished = false,
    ) {
    }
}
