<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;

describe('Client timeout configuration', function () {

    it('has default timeout matching TcpTransport default', function () {
        $client = new Client();
        expect($client->getTimeout())->toBe(TcpTransport::DAFAUT_TIMEOUT);
    });

    it('setTimeout updates the timeout value', function () {
        $client = new Client();
        $client->setTimeout(15.0);
        expect($client->getTimeout())->toBe(15.0);
    });

    it('setTimeout returns self for fluent chaining', function () {
        $client = new Client();
        $result = $client->setTimeout(10.0);
        expect($result)->toBe($client);
    });

    it('supports fluent chaining with other configuration methods', function () {
        $client = new Client();
        $result = $client
            ->setTimeout(10.0)
            ->setSecurityPolicy(SecurityPolicy::None)
            ->setSecurityMode(SecurityMode::None);
        expect($result)->toBe($client);
        expect($client->getTimeout())->toBe(10.0);
    });

    it('accepts fractional seconds', function () {
        $client = new Client();
        $client->setTimeout(0.5);
        expect($client->getTimeout())->toBe(0.5);
    });

    it('can be updated multiple times', function () {
        $client = new Client();
        $client->setTimeout(10.0);
        expect($client->getTimeout())->toBe(10.0);

        $client->setTimeout(30.0);
        expect($client->getTimeout())->toBe(30.0);
    });

    it('implements OpcUaClientInterface timeout methods', function () {
        $client = new Client();
        expect($client)->toBeInstanceOf(OpcUaClientInterface::class);

        $reflection = new ReflectionClass(OpcUaClientInterface::class);
        expect($reflection->hasMethod('setTimeout'))->toBeTrue();
        expect($reflection->hasMethod('getTimeout'))->toBeTrue();
    });
});
