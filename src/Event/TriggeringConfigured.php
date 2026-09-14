<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;

/**
 * Dispatched after a SetTriggering operation completes.
 *
 * @see \PhpOpcua\Client\Module\Subscription\SubscriptionModule::setTriggering()
 */
readonly class TriggeringConfigured
{
    /**
     * @param OpcUaClientInterface $client
     * @param int $subscriptionId
     * @param int $triggeringItemId
     * @param int[] $addResults
     * @param int[] $removeResults
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public int $subscriptionId,
        public int $triggeringItemId,
        public array $addResults,
        public array $removeResults,
    ) {
    }
}
