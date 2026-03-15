<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Error Handling', function () {

    it('returns BadNodeIdUnknown for a non-existent node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $dv = $client->read(NodeId::string(1, 'NonExistentNode'));
            expect(StatusCode::isBad($dv->getStatusCode()))->toBeTrue();
            expect($dv->getStatusCode())->toBe(StatusCode::BadNodeIdUnknown);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns BadNotWritable when writing to a read-only node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            // Read-only scalar nodes
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'ReadOnly', 'Boolean_RO']);

            $status = $client->write($nodeId, true, BuiltinType::Boolean);
            expect(StatusCode::isBad($status))->toBeTrue();
            expect($status)->toBe(StatusCode::BadNotWritable);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('returns Bad status when reading with invalid attribute', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            // Read the Server node with an invalid attribute ID (e.g. 99)
            $dv = $client->read(NodeId::numeric(0, 2253), 99);
            expect(StatusCode::isBad($dv->getStatusCode()))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
