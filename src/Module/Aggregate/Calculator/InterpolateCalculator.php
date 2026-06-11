<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate\Calculator;

use DateTimeImmutable;
use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Module\Aggregate\Interval;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

/**
 * Linear interpolation between the raw samples bracketing each interval start.
 *
 * @see https://reference.opcfoundation.org/Core/Part13/v105/docs/5.4.3.4
 */
final class InterpolateCalculator extends AbstractAggregateCalculator
{
    public function compute(Interval $interval, AggregateOptions $options, array $rawBuffer): DataValue
    {
        $bufferSize = count($rawBuffer);
        if ($bufferSize === 0) {
            return new DataValue(
                statusCode: StatusCode::BadNoData,
                sourceTimestamp: $interval->startTime,
            );
        }

        $startMs = self::toMs($interval->startTime);

        $beforeIdx = -1;
        $exactMatch = null;
        for ($i = 0; $i < $bufferSize; $i++) {
            $dv = $rawBuffer[$i];
            if ($dv->sourceTimestamp === null) {
                continue;
            }
            $ts = self::toMs($dv->sourceTimestamp);
            if ($ts > $startMs) {
                break;
            }
            if (self::isUsable($dv, $options)) {
                $beforeIdx = $i;
                if (abs($ts - $startMs) < 0.001) {
                    $exactMatch = $dv;
                }
            }
        }

        if ($exactMatch !== null) {
            $value = self::extractFloat($exactMatch);
            if ($value !== null) {
                $infoBits = StatusCode::HistorianCalculated;
                if ($interval->isPartial) {
                    $infoBits |= StatusCode::HistorianPartial;
                }

                return new DataValue(
                    new Variant(BuiltinType::Double, $value),
                    StatusCode::withDataValueInfoBits(StatusCode::Good, $infoBits),
                    $interval->startTime,
                );
            }
        }

        if ($beforeIdx < 0) {
            return new DataValue(
                statusCode: StatusCode::BadNoData,
                sourceTimestamp: $interval->startTime,
            );
        }

        $beforeDv = $rawBuffer[$beforeIdx];
        $beforeValue = self::extractFloat($beforeDv);
        if ($beforeValue === null) {
            return new DataValue(
                statusCode: StatusCode::BadNoData,
                sourceTimestamp: $interval->startTime,
            );
        }
        $beforeTimestamp = $beforeDv->sourceTimestamp;
        if ($beforeTimestamp === null) {
            return new DataValue(
                statusCode: StatusCode::BadNoData,
                sourceTimestamp: $interval->startTime,
            );
        }
        $beforeTs = self::toMs($beforeTimestamp);

        $afterIdx = -1;
        for ($i = $beforeIdx + 1; $i < $bufferSize; $i++) {
            $dv = $rawBuffer[$i];
            if ($dv->sourceTimestamp === null) {
                continue;
            }
            $ts = self::toMs($dv->sourceTimestamp);
            if ($ts <= $startMs) {
                continue;
            }
            if (self::isUsable($dv, $options) && self::extractFloat($dv) !== null) {
                $afterIdx = $i;
                break;
            }
        }

        $hasBadBetween = false;
        for ($i = $beforeIdx + 1; $i < $bufferSize; $i++) {
            $dv = $rawBuffer[$i];
            if ($dv->sourceTimestamp === null) {
                continue;
            }
            $ts = self::toMs($dv->sourceTimestamp);
            if ($ts >= $startMs) {
                break;
            }
            if (! self::isUsable($dv, $options)) {
                $hasBadBetween = true;
                break;
            }
        }

        if ($afterIdx < 0) {
            $result = $this->extrapolate($beforeIdx, $beforeValue, $beforeTs, $startMs, $rawBuffer, $options);

            $infoBits = StatusCode::HistorianCalculated | StatusCode::HistorianInterpolated;
            if ($interval->isPartial) {
                $infoBits |= StatusCode::HistorianPartial;
            }

            return new DataValue(
                new Variant(BuiltinType::Double, $result),
                StatusCode::withDataValueInfoBits(StatusCode::UncertainDataSubNormal, $infoBits),
                $interval->startTime,
            );
        }

        $afterDv = $rawBuffer[$afterIdx];
        $afterValue = (float) self::extractFloat($afterDv);
        $afterTimestamp = $afterDv->sourceTimestamp;
        if ($afterTimestamp === null) {
            return new DataValue(
                statusCode: StatusCode::BadNoData,
                sourceTimestamp: $interval->startTime,
            );
        }
        $afterTs = self::toMs($afterTimestamp);

        if ($options->stepped) {
            $result = $beforeValue;
        } else {
            $result = $beforeValue
                + ($startMs - $beforeTs) * ($afterValue - $beforeValue) / ($afterTs - $beforeTs);
        }

        $infoBits = StatusCode::HistorianCalculated | StatusCode::HistorianInterpolated;
        if ($interval->isPartial) {
            $infoBits |= StatusCode::HistorianPartial;
        }

        $severity = $hasBadBetween ? StatusCode::UncertainDataSubNormal : StatusCode::Good;

        return new DataValue(
            new Variant(BuiltinType::Double, $result),
            StatusCode::withDataValueInfoBits($severity, $infoBits),
            $interval->startTime,
        );
    }

    /**
     * @param DataValue[] $rawBuffer
     */
    private function extrapolate(
        int $beforeIdx,
        float $beforeValue,
        float $beforeTs,
        float $startMs,
        array $rawBuffer,
        AggregateOptions $options,
    ): float {
        if (! $options->useSlopedExtrapolation || $options->stepped) {
            return $beforeValue;
        }

        for ($i = $beforeIdx - 1; $i >= 0; $i--) {
            $dv = $rawBuffer[$i];
            if ($dv->sourceTimestamp === null || ! self::isUsable($dv, $options)) {
                continue;
            }
            $prev = self::extractFloat($dv);
            if ($prev === null) {
                continue;
            }
            $prevTs = self::toMs($dv->sourceTimestamp);
            if ($prevTs >= $beforeTs) {
                continue;
            }
            $slope = ($beforeValue - $prev) / ($beforeTs - $prevTs);

            return $beforeValue + $slope * ($startMs - $beforeTs);
        }

        return $beforeValue;
    }

    private static function toMs(DateTimeImmutable $t): float
    {
        return (float) $t->format('U.u') * 1000.0;
    }
}
