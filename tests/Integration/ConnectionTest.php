<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Connection', function () {

    it('connects to opcua-no-security with anonymous auth', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            expect($client)->toBeInstanceOf(Client::class);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('connects and browses root Objects folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads Server/ServerStatus/State variable', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            // ns=0, i=2259 is ServerStatus.State
            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
            // ServerState 0 = Running
            expect($dataValue->getValue())->toBeInt()->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('disconnects cleanly', function () {
        $client = TestHelper::connectNoSecurity();
        $client->disconnect();

        // After disconnect, operations should fail
        expect(fn() => $client->browse(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class);
    })->group('integration');

    it('throws on connection to invalid host', function () {
        $client = new Client();
        expect(fn() => $client->connect('opc.tcp://invalid.host.that.does.not.exist:4840/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    })->group('integration');

    it('throws on connection to invalid port', function () {
        $client = new Client();
        expect(fn() => $client->connect('opc.tcp://localhost:59999/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    })->group('integration');

})->group('integration');
