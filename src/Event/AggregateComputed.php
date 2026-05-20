<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Event;

use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\NodeId;

/**
 * Dispatched after `aggregate()` (or the wrapper `historyAggregate()`) produces
 * its windowed results. `$nodeId` is null when called on a raw in-memory buffer,
 * set when called via `historyAggregate()`.
 *
 * @see \PhpOpcua\Client\Module\Aggregate\AggregateModule::aggregate()
 * @see \PhpOpcua\Client\Module\Aggregate\AggregateModule::historyAggregate()
 */
readonly class AggregateComputed
{
    public function __construct(
        public OpcUaClientInterface $client,
        public AggregateFunction $function,
        public int $rawInputCount,
        public int $intervalCount,
        public ?NodeId $nodeId = null,
    ) {
    }
}
