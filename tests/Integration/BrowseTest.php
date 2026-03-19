<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('Browse', function () {

    it('browses the root Objects folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 85)); // Objects folder

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            // Standard OPC UA server always has a "Server" object
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the TestServer folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
            $refs = $client->browse($testServerNodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('DataTypes');
            expect($names)->toContain('Methods');
            expect($names)->toContain('Dynamic');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the DataTypes folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Scalar');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the Methods folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Add');
            expect($names)->toContain('Multiply');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the Dynamic folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Counter');
            expect($names)->toContain('Random');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses all top-level folders and verifies node structure', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
            $refs = $client->browse($testServerNodeId);

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);

            // Verify main folders exist
            expect($names)->toContain('DataTypes');
            expect($names)->toContain('Methods');
            expect($names)->toContain('Dynamic');

            // Verify they are Object nodes
            foreach ($refs as $ref) {
                $name = $ref->getBrowseName()->getName();
                if (in_array($name, ['DataTypes', 'Methods', 'Dynamic'], true)) {
                    expect($ref->getNodeClass())->toBe(NodeClass::Object);
                }
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with direction=Inverse from TestServer', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            // Inverse browse should find the parent (Objects folder)
            $refs = $client->browse(
                $testServerNodeId,
                direction: BrowseDirection::Inverse,
            );

            expect($refs)->toBeArray()->not->toBeEmpty();

            // At least one reference should point back toward Objects or Root
            $found = false;
            foreach ($refs as $ref) {
                expect($ref->isForward())->toBeFalse();
                $found = true;
            }
            expect($found)->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with direction=Both from TestServer', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $refs = $client->browse(
                $testServerNodeId,
                direction: BrowseDirection::Both,
            );

            expect($refs)->toBeArray()->not->toBeEmpty();

            $hasForward = false;
            $hasInverse = false;
            foreach ($refs as $ref) {
                if ($ref->isForward()) {
                    $hasForward = true;
                } else {
                    $hasInverse = true;
                }
            }
            expect($hasForward)->toBeTrue();
            expect($hasInverse)->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with specific reference type (Organizes)', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            // Browse Objects folder with Organizes reference type only
            $refs = $client->browse(
                NodeId::numeric(0, 85),
                direction: BrowseDirection::Forward,
                referenceTypeId: NodeId::numeric(0, 35), // Organizes
                includeSubtypes: true,
            );

            expect($refs)->toBeArray()->not->toBeEmpty();

            // All returned references should be forward
            foreach ($refs as $ref) {
                expect($ref->isForward())->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
