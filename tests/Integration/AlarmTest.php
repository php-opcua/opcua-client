<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Alarm', function () {

    it('browses the Alarms folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $alarmsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms']);
            $refs = $client->browse($alarmsNodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('AlarmSourceValue');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads AlarmSourceValue', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'AlarmSourceValue']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            // AlarmSourceValue is an oscillating numeric value
            expect($dv->getValue())->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads OffNormalSource', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'OffNormalSource']);
            $dv = $client->read($nodeId);
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            // OffNormalSource is a toggling boolean
            expect($dv->getValue())->toBeBool();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses HighTemperatureAlarm node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'HighTemperatureAlarm']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            // Alarm nodes typically have children like ActiveState, EnabledState, etc.
            expect($names)->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses LevelAlarm node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'LevelAlarm']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses OffNormalAlarm node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'OffNormalAlarm']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads alarm ActiveState', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $alarmNodeId = TestHelper::browseToNode($client, ['TestServer', 'Alarms', 'HighTemperatureAlarm']);
            $refs = $client->browse($alarmNodeId);

            // Look for ActiveState child
            $activeStateRef = TestHelper::findRefByName($refs, 'ActiveState');
            expect($activeStateRef)->not->toBeNull();

            $dv = $client->read($activeStateRef->getNodeId());
            expect($dv->getStatusCode())->toBe(StatusCode::Good);
            // ActiveState is typically a LocalizedText like "Active" or "Inactive"
            expect($dv->getValue())->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
