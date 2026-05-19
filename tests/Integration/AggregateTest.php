<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\Aggregate\AggregateModule;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

/**
 * @param string[] $browsePath
 */
function historicalNode(Client $client, array $browsePath): NodeId
{
    return TestHelper::browseToNode($client, $browsePath);
}

describe('AggregateModule (integration)', function () {

    it('exposes aggregate and historyAggregate via __call', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect($client->hasMethod('aggregate'))->toBeTrue();
            expect($client->hasMethod('historyAggregate'))->toBeTrue();
            expect($client->hasModule(AggregateModule::class))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('computes Min/Max/Average/Count over real historical temperature samples', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = historicalNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-5 minutes');
            $endTime = new DateTimeImmutable('now');

            // Single window covering the entire span (processingInterval = 0).
            $rawDvs = $client->historyReadRaw($nodeId, $startTime, $endTime);
            expect($rawDvs)->not->toBeEmpty();

            $rawValues = array_map(fn (DataValue $dv) => $dv->getValue(), $rawDvs);
            $rawValues = array_filter($rawValues, fn ($v) => is_int($v) || is_float($v));
            $expectedMin = min($rawValues);
            $expectedMax = max($rawValues);
            $expectedAvg = array_sum($rawValues) / count($rawValues);
            $expectedCount = count($rawValues);

            $min = $client->aggregate($rawDvs, $startTime, $endTime, 0.0, AggregateFunction::Minimum);
            $max = $client->aggregate($rawDvs, $startTime, $endTime, 0.0, AggregateFunction::Maximum);
            $avg = $client->aggregate($rawDvs, $startTime, $endTime, 0.0, AggregateFunction::Average);
            $cnt = $client->aggregate($rawDvs, $startTime, $endTime, 0.0, AggregateFunction::Count);

            expect($min)->toHaveCount(1)
                ->and($max)->toHaveCount(1)
                ->and($avg)->toHaveCount(1)
                ->and($cnt)->toHaveCount(1);

            expect($min[0]->getValue())->toEqualWithDelta((float) $expectedMin, 1e-9);
            expect($max[0]->getValue())->toEqualWithDelta((float) $expectedMax, 1e-9);
            expect($avg[0]->getValue())->toEqualWithDelta($expectedAvg, 1e-9);
            expect($cnt[0]->getValue())->toBe($expectedCount);

            foreach ([$min[0], $max[0], $avg[0], $cnt[0]] as $dv) {
                expect($dv->statusCode & StatusCode::HistorianCalculated)
                    ->toBe(StatusCode::HistorianCalculated);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('produces consistent windowed averages over the same span', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = historicalNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-2 minutes');
            $endTime = new DateTimeImmutable('now');

            $windows = $client->historyAggregate(
                $nodeId,
                $startTime,
                $endTime,
                30_000.0,
                AggregateFunction::Average,
            );

            expect($windows)->toBeArray()->not->toBeEmpty();
            // ~120s / 30s = 4, +1 possible trailing partial window from DateTimeImmutable('now') drift.
            expect(count($windows))->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(5);

            $hasGoodWindow = false;
            foreach ($windows as $w) {
                if (StatusCode::isGood($w->statusCode)) {
                    $hasGoodWindow = true;
                    expect(is_float($w->getValue()) || is_int($w->getValue()))->toBeTrue();
                }
            }
            expect($hasGoodWindow)->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('windowed Min ≤ Average ≤ Max for each non-empty window', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = historicalNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-2 minutes');
            $endTime = new DateTimeImmutable('now');
            $intervalMs = 20_000.0;

            $mins = $client->historyAggregate($nodeId, $startTime, $endTime, $intervalMs, AggregateFunction::Minimum);
            $maxs = $client->historyAggregate($nodeId, $startTime, $endTime, $intervalMs, AggregateFunction::Maximum);
            $avgs = $client->historyAggregate($nodeId, $startTime, $endTime, $intervalMs, AggregateFunction::Average);

            expect($mins)->toHaveCount(count($maxs));
            expect($avgs)->toHaveCount(count($mins));

            $checked = 0;
            for ($i = 0; $i < count($mins); $i++) {
                if ($mins[$i]->statusCode === StatusCode::BadNoData) {
                    continue;
                }
                $min = $mins[$i]->getValue();
                $max = $maxs[$i]->getValue();
                $avg = $avgs[$i]->getValue();

                expect($min)->toBeLessThanOrEqual($max);
                expect($avg)->toBeGreaterThanOrEqual($min);
                expect($avg)->toBeLessThanOrEqual($max);
                $checked++;
            }
            expect($checked)->toBeGreaterThan(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('Count matches the number of Good raws in the window', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = historicalNode($client, ['TestServer', 'Historical', 'HistoricalCounter']);

            $startTime = new DateTimeImmutable('-1 minute');
            $endTime = new DateTimeImmutable('now');

            $raw = $client->historyReadRaw($nodeId, $startTime, $endTime);
            $expectedGood = count(array_filter($raw, fn (DataValue $dv) => StatusCode::isGood($dv->statusCode)));

            $counts = $client->aggregate(
                $raw,
                $startTime,
                $endTime,
                0.0,
                AggregateFunction::Count,
            );

            expect($counts[0]->getValue())->toBe($expectedGood);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('Interpolate produces a value between the bracketing raws', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = historicalNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-90 seconds');
            $endTime = new DateTimeImmutable('-30 seconds');
            $windows = $client->historyAggregate(
                $nodeId,
                $startTime,
                $endTime,
                20_000.0,
                AggregateFunction::Interpolate,
            );

            // ~60s / 20s = 3, +1 possible trailing partial window.
            expect(count($windows))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(4);

            $raw = $client->historyReadRaw($nodeId, $startTime, $endTime);
            $rawValues = array_filter(
                array_map(fn (DataValue $dv) => $dv->getValue(), $raw),
                fn ($v) => is_int($v) || is_float($v),
            );
            if (count($rawValues) === 0) {
                expect(true)->toBeTrue();

                return;
            }
            $globalMin = min($rawValues);
            $globalMax = max($rawValues);

            // Only interpolated windows are bound by [min, max]; extrapolated
            // ones (severity Uncertain_SubNormal) can legitimately escape.
            foreach ($windows as $w) {
                if (! StatusCode::isGood($w->statusCode)) {
                    continue;
                }
                $v = $w->getValue();
                expect($v)->toBeGreaterThanOrEqual($globalMin - 1e-9);
                expect($v)->toBeLessThanOrEqual($globalMax + 1e-9);
                expect($w->statusCode & StatusCode::HistorianInterpolated)
                    ->toBe(StatusCode::HistorianInterpolated);
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
