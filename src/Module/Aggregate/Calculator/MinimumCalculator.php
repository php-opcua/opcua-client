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
 * Smallest numeric value inside the interval.
 *
 * @see https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.10
 */
final class MinimumCalculator extends AbstractAggregateCalculator
{
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue
    {
        $min = null;
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

            if ($min === null || $value < $min) {
                $min = $value;
                $matches = 1;
            } elseif ($value === $min) {
                $matches++;
            }
        }

        if ($min === null) {
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
            new Variant(BuiltinType::Double, $min),
            StatusCode::withDataValueInfoBits($severity, $infoBits),
            $interval->startTime,
        );
    }
}
