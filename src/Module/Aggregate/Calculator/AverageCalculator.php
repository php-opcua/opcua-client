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
 * Arithmetic mean of Good values in the interval (not time-weighted).
 *
 * @see https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.5
 */
final class AverageCalculator extends AbstractAggregateCalculator
{
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue
    {
        $sum = 0.0;
        $count = 0;
        $hasBad = false;

        $end = $interval->index + $interval->count;
        for ($i = $interval->index; $i < $end; $i++) {
            $dv = $rawBuffer[$i];

            if (! self::isUsable($dv, $options)) {
                $hasBad = true;
                continue;
            }

            $value = self::extractFloat($dv);
            if ($value === null) {
                $hasBad = true;
                continue;
            }

            $sum += $value;
            $count++;
        }

        if ($count === 0) {
            return new DataValue(statusCode: StatusCode::BadNoData, sourceTimestamp: $interval->startTime);
        }

        $mean = $sum / $count;

        $infoBits = StatusCode::HistorianCalculated;
        if ($interval->isPartial) {
            $infoBits |= StatusCode::HistorianPartial;
        }

        $severity = $hasBad ? StatusCode::UncertainDataSubNormal : StatusCode::Good;

        return new DataValue(
            new Variant(BuiltinType::Double, $mean),
            StatusCode::withDataValueInfoBits($severity, $infoBits),
            $interval->startTime,
        );
    }
}
