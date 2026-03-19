<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

describe('Auto-retry configuration', function () {

    it('getAutoRetry defaults to 0 when never connected', function () {
        $client = new Client();
        expect($client->getAutoRetry())->toBe(0);
    });

    it('setAutoRetry returns self for fluent chaining', function () {
        $client = new Client();
        $result = $client->setAutoRetry(3);
        expect($result)->toBe($client);
    });

    it('setAutoRetry overrides the default value', function () {
        $client = new Client();
        $client->setAutoRetry(5);
        expect($client->getAutoRetry())->toBe(5);
    });

    it('setAutoRetry to 0 disables retry', function () {
        $client = new Client();
        $client->setAutoRetry(0);
        expect($client->getAutoRetry())->toBe(0);
    });

    it('setAutoRetry can be updated multiple times', function () {
        $client = new Client();
        $client->setAutoRetry(2);
        expect($client->getAutoRetry())->toBe(2);

        $client->setAutoRetry(10);
        expect($client->getAutoRetry())->toBe(10);

        $client->setAutoRetry(0);
        expect($client->getAutoRetry())->toBe(0);
    });

    it('supports fluent chaining with setTimeout', function () {
        $client = new Client();
        $result = $client
            ->setTimeout(10.0)
            ->setAutoRetry(3);
        expect($result)->toBe($client);
        expect($client->getAutoRetry())->toBe(3);
        expect($client->getTimeout())->toBe(10.0);
    });

    it('implements OpcUaClientInterface auto-retry methods', function () {
        $reflection = new ReflectionClass(OpcUaClientInterface::class);
        expect($reflection->hasMethod('setAutoRetry'))->toBeTrue();
        expect($reflection->hasMethod('getAutoRetry'))->toBeTrue();
    });

    it('does not retry when not connected and autoRetry is set', function () {
        $client = new Client();
        $client->setAutoRetry(5);

        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});
