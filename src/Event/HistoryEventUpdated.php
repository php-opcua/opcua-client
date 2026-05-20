<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a HistoryUpdate Insert / Replace / Update on events.
 *
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyInsertEvent()
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyReplaceEvent()
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyUpdateEvent()
 */
readonly class HistoryEventUpdated
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param PerformUpdateType $operation
     * @param int $eventCount
     * @param int[] $operationResults
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $nodeId,
        public PerformUpdateType $operation,
        public int $eventCount,
        public array $operationResults,
    ) {
    }
}
