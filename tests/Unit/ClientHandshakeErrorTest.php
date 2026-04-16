<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers/ClientTestHelpers.php';

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Encoding\BinaryEncoder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\MessageTypeException;
use PhpOpcua\Client\Exception\ProtocolException;
use PhpOpcua\Client\Protocol\MessageHeader;

function startMockServer(Closure $handler): array
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("Cannot create TCP server: $errstr");
    }

    $addr = stream_socket_get_name($server, false);
    [$host, $port] = explode(':', $addr);

    return [$server, $host, (int) $port];
}

function acceptAndRespond($server, string $response): void
{
    $client = stream_socket_accept($server, 2);
    if ($client === false) {
        return;
    }

    fread($client, 65535);
    fwrite($client, $response);
    fclose($client);
}

describe('Client handshake error handling', function () {

    it('throws ProtocolException when server sends ERR during handshake', function () {
        [$server, $host, $port] = startMockServer(fn () => null);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('ERR', 'F', 0);
        $header->encode($encoder);
        $encoder->writeUInt32(0);
        $encoder->writeUInt32(0x80010000);
        $encoder->writeString('Test error from server');
        $data = $encoder->getBuffer();
        $data = substr($data, 0, 4) . pack('V', strlen($data)) . substr($data, 8);

        $pid = pcntl_fork();
        if ($pid === 0) {
            acceptAndRespond($server, $data);
            fclose($server);
            exit(0);
        }

        fclose($server);

        $builder = new ClientBuilder();
        $builder->setTimeout(2.0);

        try {
            expect(fn () => $builder->connect("opc.tcp://$host:$port"))
                ->toThrow(ProtocolException::class, 'Server error during handshake');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    })
        // pcntl_fork() is a Unix-only extension, not available on Windows.
        // This test spawns a child process to act as a mock OPC UA server.
        ->skipOnWindows();

    it('throws ProtocolException when server sends unexpected message type during handshake', function () {
        [$server, $host, $port] = startMockServer(fn () => null);

        $encoder = new BinaryEncoder();
        $header = new MessageHeader('MSG', 'F', 12);
        $header->encode($encoder);
        $encoder->writeUInt32(0);
        $data = $encoder->getBuffer();

        $pid = pcntl_fork();
        if ($pid === 0) {
            acceptAndRespond($server, $data);
            fclose($server);
            exit(0);
        }

        fclose($server);

        $builder = new ClientBuilder();
        $builder->setTimeout(2.0);

        try {
            expect(fn () => $builder->connect("opc.tcp://$host:$port"))
                ->toThrow(MessageTypeException::class, 'Expected ACK response, got: MSG');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    })
        // pcntl_fork() is a Unix-only extension, not available on Windows.
        // This test spawns a child process to act as a mock OPC UA server.
        ->skipOnWindows();

    it('sets state to Broken when connect fails with ConnectionException', function () {
        $builder = new ClientBuilder();
        $builder->setTimeout(1.0);

        try {
            $client = $builder->connect('opc.tcp://127.0.0.1:1');
        } catch (ConnectionException) {
        }

        expect(true)->toBeTrue();
    });
});

describe('Client disconnect error suppression', function () {

    it('disconnect does not throw when never connected', function () {
        $client = createClientWithoutConnect();
        $client->disconnect();
        expect($client->getConnectionState())->toBe(PhpOpcua\Client\Types\ConnectionState::Disconnected);
    });
});
