<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate\Calculator;

use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Module\Aggregate\Interval;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * Number of usable raw samples in the interval (Int32, Good even when 0).
 *
 * @see https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.21
 */
final class CountCalculator extends AbstractAggregateCalculator
{
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue
    {
        $count = 0;

        $end = $interval->index + $interval->count;
        for ($i = $interval->index; $i < $end; $i++) {
            $dv = $rawBuffer[$i];
            if (self::isUsable($dv, $options)) {
                $count++;
            }
        }

        $infoBits = StatusCode::HistorianCalculated;
        if ($interval->isPartial) {
            $infoBits |= StatusCode::HistorianPartial;
        }

        return new DataValue(
            new Variant(BuiltinType::Int32, $count),
            StatusCode::withDataValueInfoBits(StatusCode::Good, $infoBits),
            $interval->startTime,
        );
    }
}
