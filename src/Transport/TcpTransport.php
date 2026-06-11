<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Transport;

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ProtocolException;

/**
 * TCP socket transport for OPC UA binary protocol communication.
 *
 * Default implementation of {@see ClientTransportInterface} — opens an
 * `opc.tcp://` connection via {@see stream_socket_client()} and exchanges
 * framed OPC UA messages with the server.
 */
class TcpTransport implements ClientTransportInterface
{
    /** @var resource|null */
    private $socket = null;

    private int $receiveBufferSize = 65535;

    public const  DEFAULT_TIMEOUT = 5.0;

    /**
     * Wrap an already-connected stream socket, bypassing {@see connect()}.
     * Used by the Reverse Connect flow (OPC UA Part 6 §7.1.2.3). The factory
     * takes ownership of the socket: {@see close()} will `fclose()` it.
     *
     * @see https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3
     *
     * @param resource $socket Stream socket already in CONNECTED state.
     * @param null|float $readTimeout `null` → {@see DEFAULT_TIMEOUT}.
     *
     * @throws ConnectionException If `$socket` is not a stream resource.
     */
    public static function fromConnectedSocket(mixed $socket, null|float $readTimeout = null): self
    {
        if (! is_resource($socket)) {
            throw new ConnectionException('fromConnectedSocket requires a valid stream resource');
        }

        if ($readTimeout === null) {
            $readTimeout = self::DEFAULT_TIMEOUT;
        }

        stream_set_timeout($socket, (int) $readTimeout);

        $transport = new self();
        $transport->socket = $socket;

        return $transport;
    }

    /**
     * @param string $host
     * @param int $port
     * @param null|float $timeout
     */
    public function connect(string $host, int $port, null|float $timeout = null): void
    {
        if ($timeout === null) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
        );

        if ($socket === false) {
            throw new ConnectionException("Failed to connect to {$host}:{$port}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, (int) $timeout);
        $this->socket = $socket;
    }

    /**
     * @param string $data
     */
    public function send(string $data): void
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected');
        }

        $totalSent = 0;
        $length = strlen($data);

        while ($totalSent < $length) {
            $sent = @fwrite($this->socket, substr($data, $totalSent));
            if ($sent === false || $sent === 0) {
                throw new ConnectionException('Failed to send data');
            }
            $totalSent += $sent;
        }
    }

    /**
     * Read a complete OPC UA message from the socket.
     */
    public function receive(): string
    {
        if ($this->socket === null) {
            throw new ConnectionException('Not connected');
        }

        $header = $this->readExact(8);

        $size = unpack('V', $header, 4);
        if ($size === false) {
            throw new ProtocolException('Failed to parse message size header');
        }
        $messageSize = $size[1];

        if ($messageSize < 8 || $messageSize > $this->receiveBufferSize) {
            throw new ProtocolException("Invalid message size: {$messageSize}");
        }

        $remaining = $messageSize - 8;
        if ($remaining > 0) {
            $body = $this->readExact($remaining);

            return $header . $body;
        }

        return $header;
    }

    /**
     * @param int $length
     */
    private function readExact(int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    throw new ConnectionException('Read timeout');
                }
                throw new ConnectionException('Connection closed by remote');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * @param int $size
     */
    public function setReceiveBufferSize(int $size): void
    {
        $this->receiveBufferSize = $size;
    }

    /**
     * Close the TCP connection.
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Check whether the TCP socket is open.
     */
    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    /**
     * A fresh `TcpTransport` for use as a discovery probe — same defaults,
     * its own socket.
     */
    public function createProbe(): self
    {
        return new self();
    }

    /**
     * `opc.tcp://` relies on the OPC UA secure channel; TLS is not involved.
     */
    public function isSecureChannelExternal(): bool
    {
        return false;
    }
}
