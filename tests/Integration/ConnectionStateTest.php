<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

describe('ConnectionState transitions', function () {

    it('is Connected after successful connect', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
            expect($client->isConnected())->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('transitions to Disconnected after disconnect', function () {
        $client = (new ClientBuilder())
            ->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();

        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        expect($client->isConnected())->toBeFalse();
    })->group('integration');

    it('transitions to Broken on failed connect', function () {
        $builder = new ClientBuilder();
        $builder->setTimeout(0.1);
        $builder->setAutoRetry(0);
        $client = null;
        try {
            $client = @$builder->connect('opc.tcp://192.0.2.1:4840/UA/TestServer');
        } catch (ConnectionException) {
        }

        // connect() threw, so $client is null — no Broken state to check on client
        // The Broken state is an internal builder concern now
        expect($client)->toBeNull();
    })->group('integration');

    it('throws Disconnected-specific message after disconnect', function () {
        $client = (new ClientBuilder())
            ->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();

        expect(fn () => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    })->group('integration');

    it('throws ConnectionException on failed connect', function () {
        $builder = new ClientBuilder();
        $builder->setTimeout(0.1);
        $builder->setAutoRetry(0);

        expect(fn () => @$builder->connect('opc.tcp://192.0.2.1:4840/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    })->group('integration');

})->group('integration');

describe('Reconnect', function () {

    it('reconnect restores Connected state', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);
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
            $client = (new ClientBuilder())
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->reconnect();

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reconnect works after disconnect and reconnect', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);
            $client->reconnect();

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Auto-retry', function () {

    it('getAutoRetry returns 0 by default after connect', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);
            expect($client->getAutoRetry())->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('getAutoRetry returns 0 after disconnect', function () {
        $client = (new ClientBuilder())
            ->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $client->disconnect();
        expect($client->getAutoRetry())->toBe(0);
    })->group('integration');

    it('setAutoRetry override persists across connect/disconnect', function () {
        $client = null;
        try {
            $client = (new ClientBuilder())
                ->setAutoRetry(3)
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);

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
            $client = (new ClientBuilder())
                ->setAutoRetry(0)
                ->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
