<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

describe('Client throws ConnectionException when not connected', function () {

    it('throws on browse', function () {
        $client = new Client();
        expect(fn() => $client->browse(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on browseWithContinuation', function () {
        $client = new Client();
        expect(fn() => $client->browseWithContinuation(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on browseNext', function () {
        $client = new Client();
        expect(fn() => $client->browseNext('some-continuation'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on read', function () {
        $client = new Client();
        expect(fn() => $client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on readMulti', function () {
        $client = new Client();
        expect(fn() => $client->readMulti([['nodeId' => NodeId::numeric(0, 2259)]]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on write', function () {
        $client = new Client();
        expect(fn() => $client->write(NodeId::numeric(1, 100), 42, BuiltinType::Int32))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on writeMulti', function () {
        $client = new Client();
        expect(fn() => $client->writeMulti([
            ['nodeId' => NodeId::numeric(1, 100), 'value' => 42, 'type' => BuiltinType::Int32],
        ]))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on call', function () {
        $client = new Client();
        expect(fn() => $client->call(NodeId::numeric(1, 100), NodeId::numeric(1, 200)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on createSubscription', function () {
        $client = new Client();
        expect(fn() => $client->createSubscription())
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on createMonitoredItems', function () {
        $client = new Client();
        expect(fn() => $client->createMonitoredItems(1, [NodeId::numeric(0, 2259)]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on deleteMonitoredItems', function () {
        $client = new Client();
        expect(fn() => $client->deleteMonitoredItems(1, [1]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on publish', function () {
        $client = new Client();
        expect(fn() => $client->publish())
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadRaw', function () {
        $client = new Client();
        expect(fn() => $client->historyReadRaw(
            NodeId::numeric(1, 100),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadProcessed', function () {
        $client = new Client();
        expect(fn() => $client->historyReadProcessed(
            NodeId::numeric(1, 100),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            500.0,
            NodeId::numeric(0, 2341),
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadAtTime', function () {
        $client = new Client();
        expect(fn() => $client->historyReadAtTime(
            NodeId::numeric(1, 100),
            [new DateTimeImmutable()],
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on getEndpoints', function () {
        $client = new Client();
        expect(fn() => $client->getEndpoints('opc.tcp://localhost:4840'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});

describe('Client configuration methods', function () {

    it('setSecurityPolicy returns self for chaining', function () {
        $client = new Client();
        $result = $client->setSecurityPolicy(\Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy::None);
        expect($result)->toBe($client);
    });

    it('setSecurityMode returns self for chaining', function () {
        $client = new Client();
        $result = $client->setSecurityMode(\Gianfriaur\OpcuaPhpClient\Security\SecurityMode::None);
        expect($result)->toBe($client);
    });

    it('setUserCredentials returns self for chaining', function () {
        $client = new Client();
        $result = $client->setUserCredentials('user', 'pass');
        expect($result)->toBe($client);
    });

    it('setClientCertificate returns self for chaining', function () {
        $client = new Client();
        $result = $client->setClientCertificate('/cert.pem', '/key.pem');
        expect($result)->toBe($client);
    });

    it('setUserCertificate returns self for chaining', function () {
        $client = new Client();
        $result = $client->setUserCertificate('/cert.pem', '/key.pem');
        expect($result)->toBe($client);
    });

    it('disconnect does not throw when not connected', function () {
        $client = new Client();
        $client->disconnect();
        expect(true)->toBeTrue();
    });
});
