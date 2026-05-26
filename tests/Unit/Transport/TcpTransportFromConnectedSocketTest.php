<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Transport\TcpTransport;

/**
 * The tests use stream_socket_server() + stream_socket_accept() over loopback
 * TCP instead of stream_socket_pair(STREAM_PF_UNIX, …) so they stay portable
 * across Linux, macOS, and Windows (Unix domain sockets do not exist on
 * Windows). The Windows-incompatible socketpair() syscall is therefore never
 * touched.
 */

/**
 * @return array{0: resource, 1: resource, 2: resource} listener, accepted socket, client socket
 */
function rcConnectedPair(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("stream_socket_server failed: [{$errno}] {$errstr}");
    }
    $addr = stream_socket_get_name($server, false);
    [, $port] = explode(':', $addr);

    $clientErrno = 0;
    $clientErrstr = '';
    $client = @stream_socket_client(
        "tcp://127.0.0.1:{$port}",
        $clientErrno,
        $clientErrstr,
        2.0,
    );
    if ($client === false) {
        fclose($server);
        throw new RuntimeException("stream_socket_client failed: [{$clientErrno}] {$clientErrstr}");
    }

    $accepted = stream_socket_accept($server, 2);
    if ($accepted === false) {
        fclose($client);
        fclose($server);
        throw new RuntimeException('stream_socket_accept failed');
    }

    return [$server, $accepted, $client];
}

describe('TcpTransport::fromConnectedSocket', function () {

    it('throws ConnectionException when given a non-resource', function () {
        expect(fn () => TcpTransport::fromConnectedSocket('not a resource'))
            ->toThrow(ConnectionException::class, 'valid stream resource');
    });

    it('throws ConnectionException when given null', function () {
        expect(fn () => TcpTransport::fromConnectedSocket(null))
            ->toThrow(ConnectionException::class, 'valid stream resource');
    });

    it('throws ConnectionException when given an integer', function () {
        expect(fn () => TcpTransport::fromConnectedSocket(42))
            ->toThrow(ConnectionException::class, 'valid stream resource');
    });

    it('reports isConnected=true when built from a live connected socket', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a);

        try {
            expect($transport->isConnected())->toBeTrue();
        } finally {
            $transport->close();
            @fclose($b);
            @fclose($server);
        }
    });

    it('does not call stream_socket_client (writes go straight to the provided socket)', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a);

        try {
            $payload = 'HELF' . pack('V', 8);
            $transport->send($payload);

            $received = fread($b, 8);
            expect($received)->toBe($payload);
        } finally {
            $transport->close();
            @fclose($b);
            @fclose($server);
        }
    });

    it('receive() parses a frame written by the peer of the socket', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a, 2.0);

        try {
            $frame = 'ACKF' . pack('V', 12) . "\x01\x02\x03\x04";
            fwrite($b, $frame);

            $received = $transport->receive();
            expect($received)->toBe($frame);
        } finally {
            $transport->close();
            @fclose($b);
            @fclose($server);
        }
    });

    it('close() releases the underlying socket', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a);
        $transport->close();

        expect($transport->isConnected())->toBeFalse();
        @fclose($b);
        @fclose($server);
    });

    it('applies the default timeout when null is passed', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a, null);

        try {
            expect($transport->isConnected())->toBeTrue();
        } finally {
            $transport->close();
            @fclose($b);
            @fclose($server);
        }
    });

    it('propagates ProtocolException when the peer writes an invalid frame', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a, 2.0);

        try {
            fwrite($b, "MSG\x46" . pack('V', 0));

            expect(fn () => $transport->receive())
                ->toThrow(ProtocolException::class, 'Invalid message size');
        } finally {
            $transport->close();
            @fclose($b);
            @fclose($server);
        }
    });

    it('propagates ConnectionException when the peer closes mid-read', function () {
        [$server, $a, $b] = rcConnectedPair();

        $transport = TcpTransport::fromConnectedSocket($a, 2.0);

        try {
            fwrite($b, "MSG\x46" . pack('V', 100));
            fclose($b);

            expect(fn () => $transport->receive())
                ->toThrow(ConnectionException::class);
        } finally {
            $transport->close();
            @fclose($server);
        }
    });
});
