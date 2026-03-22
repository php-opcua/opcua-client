<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Transport;

use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ProtocolException;

/**
 * TCP socket transport for OPC UA binary protocol communication.
 */
class TcpTransport
{
    /** @var resource|null */
    private $socket = null;
    private int $receiveBufferSize = 65535;

    public const  DEFAULT_TIMEOUT = 5.0;

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

        stream_set_timeout($socket, (int)$timeout);
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
}
