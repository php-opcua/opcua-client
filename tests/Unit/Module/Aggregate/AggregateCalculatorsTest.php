<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Aggregate\AggregateOptions;
use PhpOpcua\Client\Module\Aggregate\Calculator\AverageCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\CountCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\InterpolateCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\MaximumCalculator;
use PhpOpcua\Client\Module\Aggregate\Calculator\MinimumCalculator;
use PhpOpcua\Client\Module\Aggregate\Interval;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

function aggDv(float $tsSeconds, float $value, int $status = StatusCode::Good): DataValue
{
    $ts = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $tsSeconds));
    if ($ts === false) {
        throw new RuntimeException('bad timestamp');
    }

    return new DataValue(new Variant(BuiltinType::Double, $value), $status, $ts);
}

function aggInterval(float $startSeconds, float $endSeconds, int $index, int $count, bool $partial = false): Interval
{
    $start = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $startSeconds));
    $end = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $endSeconds));
    if ($start === false || $end === false) {
        throw new RuntimeException('bad ts');
    }

    return new Interval($start, $end, ($endSeconds - $startSeconds) * 1000.0, $index, $count, $partial);
}

describe('MinimumCalculator', function () {

    it('returns the smallest Good value', function () {
        $raw = [aggDv(1000.0, 5.0), aggDv(1000.5, 2.0), aggDv(1000.9, 7.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new MinimumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(2.0);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
        expect($result->statusCode & StatusCode::HistorianCalculated)->toBe(StatusCode::HistorianCalculated);
        expect($result->sourceTimestamp?->getTimestamp())->toBe(1000);
    });

    it('returns BadNoData when the window has no usable samples', function () {
        $raw = [];
        $interval = aggInterval(1000.0, 1001.0, 0, 0, true);

        $result = (new MinimumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->statusCode)->toBe(StatusCode::BadNoData);
    });

    it('flags HistorianMultiValue when multiple raws share the minimum', function () {
        $raw = [aggDv(1000.0, 3.0), aggDv(1000.5, 3.0), aggDv(1000.9, 5.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new MinimumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->statusCode & StatusCode::HistorianMultiValue)->toBe(StatusCode::HistorianMultiValue);
    });

    it('downgrades to Uncertain_SubNormal when a Bad sample is present', function () {
        $raw = [
            aggDv(1000.0, 5.0),
            aggDv(1000.5, 99.0, StatusCode::BadNoData),
            aggDv(1000.9, 2.0),
        ];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new MinimumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(2.0);
        $base = $result->statusCode & 0xFFFF0000;
        expect($base)->toBe(StatusCode::UncertainDataSubNormal);
    });
});

describe('MaximumCalculator', function () {

    it('returns the largest Good value', function () {
        $raw = [aggDv(1000.0, 5.0), aggDv(1000.5, 2.0), aggDv(1000.9, 7.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new MaximumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(7.0);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
    });

    it('returns BadNoData on empty interval', function () {
        $result = (new MaximumCalculator())->compute(
            aggInterval(1000.0, 1001.0, 0, 0),
            AggregateOptions::default(),
            [],
        );
        expect($result->statusCode)->toBe(StatusCode::BadNoData);
    });

    it('sets HistorianPartial when the window is partial', function () {
        $raw = [aggDv(1000.0, 5.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 1, true);

        $result = (new MaximumCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->statusCode & StatusCode::HistorianPartial)->toBe(StatusCode::HistorianPartial);
    });
});

describe('AverageCalculator', function () {

    it('computes arithmetic mean of Good values', function () {
        $raw = [aggDv(1000.0, 1.0), aggDv(1000.5, 2.0), aggDv(1000.9, 6.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new AverageCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(3.0);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
    });

    it('returns BadNoData when no Good samples', function () {
        $result = (new AverageCalculator())->compute(
            aggInterval(1000.0, 1001.0, 0, 0),
            AggregateOptions::default(),
            [],
        );
        expect($result->statusCode)->toBe(StatusCode::BadNoData);
    });

    it('treats Uncertain as bad by default', function () {
        $raw = [
            aggDv(1000.0, 10.0, StatusCode::UncertainDataSubNormal),
            aggDv(1000.5, 20.0),
        ];
        $interval = aggInterval(1000.0, 1001.0, 0, 2);

        $result = (new AverageCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(20.0);
        $base = $result->statusCode & 0xFFFF0000;
        expect($base)->toBe(StatusCode::UncertainDataSubNormal);
    });

    it('includes Uncertain when treatUncertainAsBad is false', function () {
        $raw = [
            aggDv(1000.0, 10.0, StatusCode::UncertainDataSubNormal),
            aggDv(1000.5, 20.0),
        ];
        $interval = aggInterval(1000.0, 1001.0, 0, 2);
        $opts = new AggregateOptions(treatUncertainAsBad: false);

        $result = (new AverageCalculator())->compute($interval, $opts, $raw);

        expect($result->getValue())->toBe(15.0);
    });
});

describe('CountCalculator', function () {

    it('counts usable raw samples', function () {
        $raw = [aggDv(1000.0, 1.0), aggDv(1000.5, 2.0), aggDv(1000.9, 3.0)];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new CountCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(3);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
    });

    it('returns Good 0 (not BadNoData) on empty interval', function () {
        $result = (new CountCalculator())->compute(
            aggInterval(1000.0, 1001.0, 0, 0),
            AggregateOptions::default(),
            [],
        );

        expect($result->getValue())->toBe(0);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
    });

    it('ignores Bad samples', function () {
        $raw = [
            aggDv(1000.0, 1.0),
            aggDv(1000.5, 2.0, StatusCode::BadNoData),
            aggDv(1000.9, 3.0),
        ];
        $interval = aggInterval(1000.0, 1001.0, 0, 3);

        $result = (new CountCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(2);
    });
});

describe('InterpolateCalculator', function () {

    it('linearly interpolates between two bracketing samples', function () {
        // before: t=1000, v=10.0 ; after: t=1002, v=20.0 ; interval start = 1001 → expect 15.0
        $raw = [aggDv(1000.0, 10.0), aggDv(1002.0, 20.0)];
        $interval = aggInterval(1001.0, 1003.0, 1, 1);

        $result = (new InterpolateCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(15.0);
        $base = $result->statusCode & 0xFFFF0000;
        expect($base)->toBe(StatusCode::Good);
        expect($result->statusCode & StatusCode::HistorianInterpolated)->toBe(StatusCode::HistorianInterpolated);
    });

    it('returns the exact raw value when sourceTimestamp matches startTime', function () {
        $raw = [aggDv(1001.0, 42.0)];
        $interval = aggInterval(1001.0, 1003.0, 0, 1);

        $result = (new InterpolateCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(42.0);
        expect(StatusCode::isGood($result->statusCode))->toBeTrue();
    });

    it('returns BadNoData when there is no sample at or before startTime', function () {
        $raw = [aggDv(1005.0, 10.0)];
        $interval = aggInterval(1001.0, 1003.0, 0, 0);

        $result = (new InterpolateCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->statusCode)->toBe(StatusCode::BadNoData);
    });

    it('uses stepped value when option is set', function () {
        $raw = [aggDv(1000.0, 10.0), aggDv(1002.0, 20.0)];
        $interval = aggInterval(1001.0, 1003.0, 1, 1);
        $opts = new AggregateOptions(stepped: true);

        $result = (new InterpolateCalculator())->compute($interval, $opts, $raw);

        expect($result->getValue())->toBe(10.0);
    });

    it('extrapolates with slope past the last raw sample when enabled', function () {
        // last two: t=1000 v=10, t=1002 v=20 → slope=5/sec.
        // requested startTime=1004 → 20 + 5*(1004-1002) = 30
        $raw = [aggDv(1000.0, 10.0), aggDv(1002.0, 20.0)];
        $interval = aggInterval(1004.0, 1005.0, 1, 0);
        $opts = new AggregateOptions(useSlopedExtrapolation: true);

        $result = (new InterpolateCalculator())->compute($interval, $opts, $raw);

        expect($result->getValue())->toBe(30.0);
        $base = $result->statusCode & 0xFFFF0000;
        expect($base)->toBe(StatusCode::UncertainDataSubNormal);
    });

    it('re-uses the last raw sample when extrapolating without sloped mode', function () {
        $raw = [aggDv(1000.0, 10.0), aggDv(1002.0, 20.0)];
        $interval = aggInterval(1004.0, 1005.0, 1, 0);

        $result = (new InterpolateCalculator())->compute($interval, AggregateOptions::default(), $raw);

        expect($result->getValue())->toBe(20.0);
        $base = $result->statusCode & 0xFFFF0000;
        expect($base)->toBe(StatusCode::UncertainDataSubNormal);
    });
});
