<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Module\Aggregate\Calculator;

use PhpOpcua\Client\Module\Aggregate\AggregateCalculatorInterface;
use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

/**
 * Shared helpers for the built-in aggregate calculators.
 */
abstract class AbstractAggregateCalculator implements AggregateCalculatorInterface
{
    protected static function isUsable(DataValue $dv, AggregateOptions $options): bool
    {
        if (StatusCode::isGood($dv->statusCode)) {
            return true;
        }

        if (StatusCode::isUncertain($dv->statusCode) && ! $options->treatUncertainAsBad) {
            return true;
        }

        return false;
    }

    protected static function extractFloat(DataValue $dv): ?float
    {
        $value = $dv->getValue();
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return null;
    }
}
