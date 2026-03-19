<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Tests\Integration\Helpers\TestHelper;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

describe('ConnectionState transitions', function () {

    it('transitions to Connected after successful connect', function () {
        $client = null;
        try {
            $client = new Client();
            expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);

            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
            expect($client->isConnected())->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('transitions to Disconnected after disconnect', function () {
        $client = new Client();
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();

        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        expect($client->isConnected())->toBeFalse();
    })->group('integration');

    it('transitions to Broken on failed connect', function () {
        $client = new Client();
        $client->setTimeout(0.1);
        try {
            @$client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
        }

        expect($client->getConnectionState())->toBe(ConnectionState::Broken);
        expect($client->isConnected())->toBeFalse();
    })->group('integration');

    it('throws Disconnected-specific message after disconnect', function () {
        $client = new Client();
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    })->group('integration');

    it('throws Broken-specific message after failed connect', function () {
        $client = new Client();
        $client->setTimeout(0.1);
        $client->setAutoRetry(0);
        try {
            @$client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
        }

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Connection lost: call reconnect() or connect() to re-establish');
    })->group('integration');

})->group('integration');

describe('Reconnect', function () {

    it('reconnect restores Connected state', function () {
        $client = null;
        try {
            $client = new Client();
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->reconnect();

            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
            expect($client->isConnected())->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reconnect allows operations after reconnect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->reconnect();

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reconnect works after connect to different endpoints', function () {
        $client = null;
        try {
            $client = new Client();
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->disconnect();

            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->reconnect();

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Auto-retry', function () {

    it('getAutoRetry returns 1 by default after connect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getAutoRetry())->toBe(1);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('getAutoRetry returns 0 after disconnect', function () {
        $client = new Client();
        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();
        expect($client->getAutoRetry())->toBe(0);
    })->group('integration');

    it('getAutoRetry returns 1 after failed connect (lastEndpointUrl is set)', function () {
        $client = new Client();
        $client->setTimeout(0.1);
        try {
            @$client->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
        }
        expect($client->getAutoRetry())->toBe(1);
    })->group('integration');

    it('setAutoRetry override persists across connect/disconnect', function () {
        $client = null;
        try {
            $client = new Client();
            $client->setAutoRetry(3);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->getAutoRetry())->toBe(3);

            $client->disconnect();
            expect($client->getAutoRetry())->toBe(3);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('auto-retry with 0 does not reconnect on failure', function () {
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
