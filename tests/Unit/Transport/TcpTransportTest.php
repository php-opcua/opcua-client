<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;

describe('TcpTransport', function () {

    it('isConnected returns false initially', function () {
        $transport = new TcpTransport();
        expect($transport->isConnected())->toBeFalse();
    });

    it('throws ConnectionException on send when not connected', function () {
        $transport = new TcpTransport();
        expect(fn() => $transport->send('data'))
            ->toThrow(ConnectionException::class, 'Not connected');
    });

    it('throws ConnectionException on receive when not connected', function () {
        $transport = new TcpTransport();
        expect(fn() => $transport->receive())
            ->toThrow(ConnectionException::class, 'Not connected');
    });

    it('close does nothing when not connected', function () {
        $transport = new TcpTransport();
        $transport->close(); // should not throw
        expect($transport->isConnected())->toBeFalse();
    });

    it('setReceiveBufferSize sets the buffer size', function () {
        $transport = new TcpTransport();
        // Should not throw
        $transport->setReceiveBufferSize(131072);
        expect(true)->toBeTrue();
    });

    it('throws ConnectionException when connecting to unreachable host', function () {
        $transport = new TcpTransport();
        // Connect to an unreachable address with very short timeout
        expect(fn() => $transport->connect('192.0.2.1', 1, 0.1))
            ->toThrow(ConnectionException::class);
    });

    it('throws ConnectionException when connecting to refused port', function () {
        $transport = new TcpTransport();
        // Port 1 should be refused on localhost
        expect(fn() => $transport->connect('127.0.0.1', 1, 0.5))
            ->toThrow(ConnectionException::class);
    });
});
