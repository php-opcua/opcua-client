<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\Module\History\PerformUpdateType;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a HistoryUpdate Insert / Replace / Update on raw data.
 *
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyInsertData()
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyReplaceData()
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyUpdateData()
 */
readonly class HistoryDataUpdated
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param PerformUpdateType $operation
     * @param int $valueCount
     * @param int[] $operationResults
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $nodeId,
        public PerformUpdateType $operation,
        public int $valueCount,
        public array $operationResults,
    ) {
    }
}
