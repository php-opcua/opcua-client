<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate;

use PhpOpcua\Client\Types\DataValue;

/**
 * Strategy contract for a single aggregate function.
 */
interface AggregateCalculatorInterface
{
    /**
     * @param Interval $interval
     * @param AggregateOptions $options
     * @param DataValue[] $rawBuffer Ascending by sourceTimestamp.
     * @return DataValue
     */
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue;
}
