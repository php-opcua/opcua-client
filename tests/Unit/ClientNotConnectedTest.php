<?php

declare(strict_types=1);

require_once __DIR__ . '/Client/ClientTraitsCoverageTest.php';

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;

describe('Client throws ConnectionException when not connected', function () {

    beforeEach(function () {
        $this->client = createClientWithoutConnect();
        registerClientModules($this->client);
    });

    it('throws on browse', function () {
        expect(fn () => $this->client->browse(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on browseWithContinuation', function () {
        expect(fn () => $this->client->browseWithContinuation(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on browseNext', function () {
        expect(fn () => $this->client->browseNext('some-continuation'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on read', function () {
        expect(fn () => $this->client->read(NodeId::numeric(0, 2259)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on readMulti', function () {
        expect(fn () => $this->client->readMulti([['nodeId' => NodeId::numeric(0, 2259)]]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on write', function () {
        expect(fn () => $this->client->write(NodeId::numeric(1, 100), 42, BuiltinType::Int32))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on writeMulti', function () {
        expect(fn () => $this->client->writeMulti([
            ['nodeId' => NodeId::numeric(1, 100), 'value' => 42, 'type' => BuiltinType::Int32],
        ]))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on call', function () {
        expect(fn () => $this->client->call(NodeId::numeric(1, 100), NodeId::numeric(1, 200)))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on createSubscription', function () {
        expect(fn () => $this->client->createSubscription())
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on createMonitoredItems', function () {
        expect(fn () => $this->client->createMonitoredItems(1, [['nodeId' => NodeId::numeric(0, 2259)]]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on deleteMonitoredItems', function () {
        expect(fn () => $this->client->deleteMonitoredItems(1, [1]))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on publish', function () {
        expect(fn () => $this->client->publish())
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadRaw', function () {
        expect(fn () => $this->client->historyReadRaw(
            NodeId::numeric(1, 100),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadProcessed', function () {
        expect(fn () => $this->client->historyReadProcessed(
            NodeId::numeric(1, 100),
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            500.0,
            NodeId::numeric(0, 2341),
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on historyReadAtTime', function () {
        expect(fn () => $this->client->historyReadAtTime(
            NodeId::numeric(1, 100),
            [new DateTimeImmutable()],
        ))->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });

    it('throws on getEndpoints', function () {
        expect(fn () => $this->client->getEndpoints('opc.tcp://localhost:4840'))
            ->toThrow(ConnectionException::class, 'Not connected: call connect() first');
    });
});

describe('ClientBuilder configuration methods', function () {

    it('setSecurityPolicy returns self for chaining', function () {
        $builder = new ClientBuilder();
        $result = $builder->setSecurityPolicy(PhpOpcua\Client\Security\SecurityPolicy::None);
        expect($result)->toBe($builder);
    });

    it('setSecurityMode returns self for chaining', function () {
        $builder = new ClientBuilder();
        $result = $builder->setSecurityMode(PhpOpcua\Client\Security\SecurityMode::None);
        expect($result)->toBe($builder);
    });

    it('setUserCredentials returns self for chaining', function () {
        $builder = new ClientBuilder();
        $result = $builder->setUserCredentials('user', 'pass');
        expect($result)->toBe($builder);
    });

    it('setClientCertificate returns self for chaining', function () {
        $builder = new ClientBuilder();
        $result = $builder->setClientCertificate('/cert.pem', '/key.pem');
        expect($result)->toBe($builder);
    });

    it('setUserCertificate returns self for chaining', function () {
        $builder = new ClientBuilder();
        $result = $builder->setUserCertificate('/cert.pem', '/key.pem');
        expect($result)->toBe($builder);
    });

    it('disconnect does not throw when not connected', function () {
        $client = createClientWithoutConnect();
        $client->disconnect();
        expect(true)->toBeTrue();
    });
});
