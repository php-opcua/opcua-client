<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Transport\TcpTransport;

describe('TcpTransport additional coverage', function () {

    it('uses default timeout when null is passed', function () {
        $transport = new TcpTransport();
        expect(fn () => $transport->connect('127.0.0.1', 1, null))
            ->toThrow(ConnectionException::class);
    });

    it('receive throws ProtocolException on invalid message size', function () {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot create local TCP server');
        }

        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        $client = stream_socket_accept($server, 2);
        fwrite($client, "MSG\x46" . pack('V', 0));

        try {
            expect(fn () => $transport->receive())
                ->toThrow(ProtocolException::class, 'Invalid message size');
        } finally {
            $transport->close();
            fclose($client);
            fclose($server);
        }
    });

    it('receive returns header-only message when remaining is 0', function () {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot create local TCP server');
        }

        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        $client = stream_socket_accept($server, 2);
        fwrite($client, "MSG\x46" . pack('V', 8));

        try {
            $result = $transport->receive();
            expect(strlen($result))->toBe(8);
            expect(substr($result, 0, 3))->toBe('MSG');
        } finally {
            $transport->close();
            fclose($client);
            fclose($server);
        }
    });

    it('receive throws ConnectionException on connection closed by remote', function () {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot create local TCP server');
        }

        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        $client = stream_socket_accept($server, 2);
        fwrite($client, "MSG\x46" . pack('V', 108));
        fclose($client);

        try {
            expect(fn () => $transport->receive())
                ->toThrow(ConnectionException::class);
        } finally {
            $transport->close();
            fclose($server);
        }
    });

    it('receive throws ConnectionException on read timeout', function () {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot create local TCP server');
        }

        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 1.0);

        $client = stream_socket_accept($server, 2);

        fwrite($client, "MSG\x46" . pack('V', 108));

        try {
            expect(fn () => $transport->receive())
                ->toThrow(ConnectionException::class, 'Read timeout');
        } finally {
            $transport->close();
            fclose($client);
            fclose($server);
        }
    });

    it('send throws ConnectionException when write fails', function () {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot create local TCP server');
        }

        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        $client = stream_socket_accept($server, 2);
        fclose($client);
        fclose($server);

        usleep(50000);

        expect(fn () => $transport->send(str_repeat('X', 1024 * 1024)))
            ->toThrow(ConnectionException::class);

        $transport->close();
    })
        // On Windows, fwrite() to a socket whose remote end was closed does not
        // fail immediately — the OS buffers the data and reports success. The
        // ConnectionException is only raised on Linux/macOS where the broken pipe
        // is detected synchronously.
        ->skipOnWindows();
});
