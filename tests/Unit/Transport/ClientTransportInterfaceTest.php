<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Tests\Unit\Helpers\InMemoryTransport;
use PhpOpcua\Client\Transport\ClientTransportInterface;
use PhpOpcua\Client\Transport\TcpTransport;

describe('ClientTransportInterface', function () {

    it('is implemented by the default TcpTransport', function () {
        expect(new TcpTransport())->toBeInstanceOf(ClientTransportInterface::class);
    });

    it('exposes the six contract methods on TcpTransport', function () {
        $reflection = new ReflectionClass(ClientTransportInterface::class);
        $names = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        expect($names)->toEqualCanonicalizing([
            'connect',
            'send',
            'receive',
            'setReceiveBufferSize',
            'close',
            'isConnected',
        ]);
    });

});

describe('InMemoryTransport (custom implementation)', function () {

    it('satisfies the ClientTransportInterface contract', function () {
        expect(new InMemoryTransport())->toBeInstanceOf(ClientTransportInterface::class);
    });

    it('records connect parameters on connect()', function () {
        $t = new InMemoryTransport();
        $t->connect('plc.local', 4840, 7.5);

        expect($t->connectedHost)->toBe('plc.local');
        expect($t->connectedPort)->toBe(4840);
        expect($t->connectTimeout)->toBe(7.5);
        expect($t->isConnected())->toBeTrue();
    });

    it('captures sent messages for assertion', function () {
        $t = new InMemoryTransport();
        $t->connect('host', 4840);

        $t->send('hello');
        $t->send('world');

        expect($t->sentMessages)->toBe(['hello', 'world']);
    });

    it('drains the response queue on receive()', function () {
        $t = new InMemoryTransport();
        $t->connect('host', 4840);
        $t->queueResponse('ack-1');
        $t->queueResponse('ack-2');

        expect($t->receive())->toBe('ack-1');
        expect($t->receive())->toBe('ack-2');
    });

    it('throws ConnectionException when send() runs while disconnected', function () {
        $t = new InMemoryTransport();
        expect(fn () => $t->send('x'))
            ->toThrow(ConnectionException::class, 'Not connected');
    });

    it('throws ConnectionException when receive() runs while disconnected', function () {
        $t = new InMemoryTransport();
        expect(fn () => $t->receive())
            ->toThrow(ConnectionException::class, 'Not connected');
    });

    it('throws ConnectionException when the response queue is exhausted', function () {
        $t = new InMemoryTransport();
        $t->connect('host', 4840);
        expect(fn () => $t->receive())
            ->toThrow(ConnectionException::class, 'Response queue exhausted');
    });

    it('records the negotiated receive buffer size', function () {
        $t = new InMemoryTransport();
        $t->setReceiveBufferSize(8192);
        expect($t->receiveBufferSize)->toBe(8192);
    });

    it('marks itself disconnected after close()', function () {
        $t = new InMemoryTransport();
        $t->connect('host', 4840);
        $t->close();
        expect($t->isConnected())->toBeFalse();
    });

});
