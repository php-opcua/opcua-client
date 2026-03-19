<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('ConnectionState enum', function () {

    it('has three cases', function () {
        $cases = ConnectionState::cases();
        expect($cases)->toHaveCount(3);
        expect(array_map(fn($c) => $c->name, $cases))
            ->toContain('Disconnected', 'Connected', 'Broken');
    });
});

describe('Client connection state', function () {

    it('starts in Disconnected state', function () {
        $client = new Client();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        expect($client->isConnected())->toBeFalse();
    });

    it('isConnected returns false when Disconnected', function () {
        $client = new Client();
        expect($client->isConnected())->toBeFalse();
    });

    it('returns to Disconnected after disconnect on a never-connected client', function () {
        $client = new Client();
        $client->disconnect();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        expect($client->isConnected())->toBeFalse();
    });

    it('throws specific message for Disconnected state', function () {
        $client = new Client();
        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws ConfigurationException on reconnect without prior connect', function () {
        $client = new Client();
        expect(fn() => $client->reconnect())
            ->toThrow(ConfigurationException::class, 'Cannot reconnect: no previous connection endpoint');
    });

    it('getAutoRetry returns 0 when never connected', function () {
        $client = new Client();
        expect($client->getAutoRetry())->toBe(0);
    });

    it('setAutoRetry returns self for chaining', function () {
        $client = new Client();
        $result = $client->setAutoRetry(3);
        expect($result)->toBe($client);
    });

    it('setAutoRetry overrides the default', function () {
        $client = new Client();
        $client->setAutoRetry(5);
        expect($client->getAutoRetry())->toBe(5);
    });

    it('setAutoRetry to 0 disables retry', function () {
        $client = new Client();
        $client->setAutoRetry(0);
        expect($client->getAutoRetry())->toBe(0);
    });
});
