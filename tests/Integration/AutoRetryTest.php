<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('Auto-retry default behavior', function () {

    it('default auto-retry is 1 after connect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getAutoRetry())->toBe(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('default auto-retry is 0 after disconnect', function () {
        $client = new Client();
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();
        expect($client->getAutoRetry())->toBe(0);
    })->group('integration');

    it('default auto-retry is 1 after failed connect (lastEndpointUrl is set)', function () {
        $client = new Client();
        $client->setTimeout(0.1);
        try {
            @$client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
        }
        expect($client->getAutoRetry())->toBe(1);
    })->group('integration');

})->group('integration');

describe('Auto-retry with explicit configuration', function () {

    it('setAutoRetry override persists after connect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setAutoRetry(3);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getAutoRetry())->toBe(3);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('setAutoRetry override persists after disconnect', function () {
        $client = new Client();
        $client->setAutoRetry(3);
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();
        expect($client->getAutoRetry())->toBe(3);
    })->group('integration');

    it('operations work normally with auto-retry enabled', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setAutoRetry(2);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();

            $results = $client->readMulti([
                ['nodeId' => NodeId::numeric(0, 2259)],
            ]);
            expect($results)->toHaveCount(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('operations work normally with auto-retry disabled', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setAutoRetry(0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Auto-retry state transitions', function () {

    it('state is Connected after successful auto-retry reconnect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setAutoRetry(1);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $client->reconnect();
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('does not retry on Disconnected state even with autoRetry set', function () {
        $client = new Client();
        $client->setAutoRetry(5);

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    })->group('integration');

    it('does not retry after explicit disconnect even with autoRetry set', function () {
        $client = new Client();
        $client->setAutoRetry(5);
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    })->group('integration');

})->group('integration');
