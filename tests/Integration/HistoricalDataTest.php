<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Historical Data', function () {

    it('reads raw historical data for HistoricalTemperature', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-5 minutes');
            $endTime = new DateTimeImmutable('now');

            $dataValues = $client->historyReadRaw($nodeId, $startTime, $endTime);

            expect($dataValues)->toBeArray()->not->toBeEmpty();

            // Each entry should be a DataValue with a numeric temperature
            foreach ($dataValues as $dv) {
                expect(StatusCode::isGood($dv->getStatusCode()) || StatusCode::isUncertain($dv->getStatusCode()))->toBeTrue();
                $val = $dv->getValue();
                expect($val)->not->toBeNull();
                expect(is_float($val) || is_int($val))->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads raw historical data for HistoricalCounter', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalCounter']);

            $startTime = new DateTimeImmutable('-5 minutes');
            $endTime = new DateTimeImmutable('now');

            $dataValues = $client->historyReadRaw($nodeId, $startTime, $endTime);

            expect($dataValues)->toBeArray()->not->toBeEmpty();

            foreach ($dataValues as $dv) {
                expect(StatusCode::isGood($dv->getStatusCode()) || StatusCode::isUncertain($dv->getStatusCode()))->toBeTrue();
                $val = $dv->getValue();
                expect($val)->not->toBeNull();
                expect(is_int($val))->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads raw historical data for HistoricalBoolean', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalBoolean']);

            $startTime = new DateTimeImmutable('-5 minutes');
            $endTime = new DateTimeImmutable('now');

            $dataValues = $client->historyReadRaw($nodeId, $startTime, $endTime);

            expect($dataValues)->toBeArray()->not->toBeEmpty();

            foreach ($dataValues as $dv) {
                expect(StatusCode::isGood($dv->getStatusCode()) || StatusCode::isUncertain($dv->getStatusCode()))->toBeTrue();
                $val = $dv->getValue();
                expect($val)->not->toBeNull();
                expect(is_bool($val))->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads historical data with numValuesPerNode limit', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Historical', 'HistoricalTemperature']);

            $startTime = new DateTimeImmutable('-5 minutes');
            $endTime = new DateTimeImmutable('now');

            $limit = 5;
            $dataValues = $client->historyReadRaw($nodeId, $startTime, $endTime, $limit);

            expect($dataValues)->toBeArray()->not->toBeEmpty();
            expect(count($dataValues))->toBeLessThanOrEqual($limit);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
