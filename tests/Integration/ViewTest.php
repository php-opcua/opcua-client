<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('View', function () {

    it('browses the Views folder from root', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            // Views folder is at ns=0, i=87
            $refs = $client->browse(NodeId::numeric(0, 87));
            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('verifies OperatorView exists', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 87));

            $operatorView = TestHelper::findRefByName($refs, 'OperatorView');
            expect($operatorView)->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('verifies EngineeringView exists', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 87));

            $engineeringView = TestHelper::findRefByName($refs, 'EngineeringView');
            expect($engineeringView)->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('verifies HistoricalView exists', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 87));

            $historicalView = TestHelper::findRefByName($refs, 'HistoricalView');
            expect($historicalView)->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('verifies DataView exists', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 87));

            $dataView = TestHelper::findRefByName($refs, 'DataView');
            expect($dataView)->not->toBeNull();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
