<?php

declare(strict_types=1);

use PhpOpcua\Client\Module\Aggregate\Interval;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\Variant;

function aggIntervalDv(float $tsSeconds, float $value): DataValue
{
    $ts = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $tsSeconds));
    if ($ts === false) {
        throw new RuntimeException('bad timestamp');
    }

    return new DataValue(new Variant(BuiltinType::Double, $value), 0, $ts);
}

describe('Interval::sliceSequence', function () {

    it('produces a single window when processingInterval is 0', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@2000');
        $intervals = Interval::sliceSequence($start, $end, 0.0, []);

        expect($intervals)->toHaveCount(1)
            ->and($intervals[0]->startTime->getTimestamp())->toBe(1000)
            ->and($intervals[0]->endTime->getTimestamp())->toBe(2000);
    });

    it('slices a 10-second span into 5 windows of 2000ms', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $intervals = Interval::sliceSequence($start, $end, 2000.0, []);

        expect($intervals)->toHaveCount(5);
        expect($intervals[0]->startTime->getTimestamp())->toBe(1000);
        expect($intervals[0]->endTime->getTimestamp())->toBe(1002);
        expect($intervals[4]->endTime->getTimestamp())->toBe(1010);
    });

    it('counts raw values that fall into each window', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $raw = [
            aggIntervalDv(1000.5, 1.0),  // window 0
            aggIntervalDv(1001.0, 2.0),  // window 0
            aggIntervalDv(1003.0, 3.0),  // window 1
            aggIntervalDv(1007.5, 4.0),  // window 3
            aggIntervalDv(1009.9, 5.0),  // window 4
        ];

        $intervals = Interval::sliceSequence($start, $end, 2000.0, $raw);

        expect($intervals[0]->count)->toBe(2)
            ->and($intervals[1]->count)->toBe(1)
            ->and($intervals[2]->count)->toBe(0)
            ->and($intervals[3]->count)->toBe(1)
            ->and($intervals[4]->count)->toBe(1);
    });

    it('uses a forward-only cursor across windows (index points into raw buffer)', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $raw = [
            aggIntervalDv(1000.5, 1.0),
            aggIntervalDv(1003.0, 3.0),
            aggIntervalDv(1009.9, 5.0),
        ];

        $intervals = Interval::sliceSequence($start, $end, 2000.0, $raw);

        expect($intervals[0]->index)->toBe(0)
            ->and($intervals[1]->index)->toBe(1)
            ->and($intervals[3]->index)->toBeGreaterThanOrEqual(2)
            ->and($intervals[4]->index)->toBe(2);
    });

    it('marks the last window partial when data runs out before endTime', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@1010');
        $raw = [
            aggIntervalDv(1000.0, 1.0),
            aggIntervalDv(1003.0, 2.0),
        ];

        $intervals = Interval::sliceSequence($start, $end, 2000.0, $raw);

        expect($intervals[0]->isPartial)->toBeFalse()
            ->and($intervals[4]->isPartial)->toBeTrue();
    });

    it('throws when endTime is not after startTime', function () {
        $t = new DateTimeImmutable('@1000');
        expect(fn () => Interval::sliceSequence($t, $t, 100.0, []))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws on negative processingInterval', function () {
        $start = new DateTimeImmutable('@1000');
        $end = new DateTimeImmutable('@2000');
        expect(fn () => Interval::sliceSequence($start, $end, -1.0, []))
            ->toThrow(InvalidArgumentException::class);
    });
});
