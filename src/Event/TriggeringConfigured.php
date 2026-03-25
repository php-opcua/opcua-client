<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Event;

use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

/**
 * Dispatched after a SetTriggering operation completes.
 *
 * @see \Gianfriaur\OpcuaPhpClient\Client\ManagesSubscriptionsTrait::setTriggering()
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
