<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after a HistoryUpdate delete on raw data.
 *
 * `$kind` is either `'rawModified'` (delete by time range, single overall status)
 * or `'atTime'` (delete by per-timestamp list, one status per timestamp).
 *
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyDeleteRawModified()
 * @see \PhpOpcua\Client\Module\History\HistoryModule::historyDeleteAtTime()
 */
readonly class HistoryDataDeleted
{
    /**
     * @param OpcUaClientInterface $client
     * @param NodeId $nodeId
     * @param string $kind 'rawModified' or 'atTime'.
     * @param int $statusCode Overall status (Good = 0 when kind = 'atTime').
     * @param int[] $operationResults Per-timestamp status codes when kind = 'atTime'; empty otherwise.
     */
    public function __construct(
        public OpcUaClientInterface $client,
        public NodeId $nodeId,
        public string $kind,
        public int $statusCode,
        public array $operationResults,
    ) {
    }
}
