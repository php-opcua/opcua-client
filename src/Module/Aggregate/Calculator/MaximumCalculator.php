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
 * Largest numeric value inside the interval.
 *
 * @see https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.11
 */
final class MaximumCalculator extends AbstractAggregateCalculator
{
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue
    {
        $max = null;
        $matches = 0;
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

            if ($max === null || $value > $max) {
                $max = $value;
                $matches = 1;
            } elseif ($value === $max) {
                $matches++;
            }
        }

        if ($max === null) {
            return new DataValue(statusCode: StatusCode::BadNoData, sourceTimestamp: $interval->startTime);
        }

        $infoBits = StatusCode::HistorianCalculated;
        if ($interval->isPartial) {
            $infoBits |= StatusCode::HistorianPartial;
        }
        if ($matches > 1) {
            $infoBits |= StatusCode::HistorianMultiValue;
        }

        $severity = $hasBad ? StatusCode::UncertainDataSubNormal : StatusCode::Good;

        return new DataValue(
            new Variant(BuiltinType::Double, $max),
            StatusCode::withDataValueInfoBits($severity, $infoBits),
            $interval->startTime,
        );
    }
}
