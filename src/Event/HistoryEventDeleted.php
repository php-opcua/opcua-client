<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a HistoryUpdate DeleteEvent operation.
 *
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyDeleteEvent()
 */
readonly class HistoryEventDeleted
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param int $eventCount Number of EventIds requested for deletion.
     * @param int[] $operationResults Per-event status codes.
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $nodeId,
        public int $eventCount,
        public array $operationResults,
    ) {
    }
}
