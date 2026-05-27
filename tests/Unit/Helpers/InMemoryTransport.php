<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Tests\Unit\Helpers;

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Transport\ClientTransportInterface;

/**
 * In-memory implementation of {@see ClientTransportInterface} for tests.
 *
 * Pre-load the queue with the responses the server would have sent via
 * {@see queueResponse()}; every {@see send()} is recorded into
 * {@see $sentMessages} so the test can assert on what the client wrote.
 *
 * Also serves as the canonical "how to write a custom transport" example
 * cited from `docs/extensibility/transport.md`.
 */
class InMemoryTransport implements ClientTransportInterface
{
    /** @var string[] */
    public array $sentMessages = [];

    /** @var string[] */
    private array $responseQueue = [];

    public ?string $connectedHost = null;

    public ?int $connectedPort = null;

    public ?float $connectTimeout = null;

    public int $receiveBufferSize = 65535;

    private bool $connected = false;

    public function connect(string $host, int $port, null|float $timeout = null): void
    {
        $this->connectedHost = $host;
        $this->connectedPort = $port;
        $this->connectTimeout = $timeout;
        $this->connected = true;
    }

    public function send(string $data): void
    {
        if (! $this->connected) {
            throw new ConnectionException('Not connected');
        }

        $this->sentMessages[] = $data;
    }

    public function receive(): string
    {
        if (! $this->connected) {
            throw new ConnectionException('Not connected');
        }

        if ($this->responseQueue === []) {
            throw new ConnectionException('Response queue exhausted');
        }

        return array_shift($this->responseQueue);
    }

    public function setReceiveBufferSize(int $size): void
    {
        $this->receiveBufferSize = $size;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function createProbe(): self
    {
        return new self();
    }

    public function isSecureChannelExternal(): bool
    {
        return false;
    }

    /**
     * Pre-load the next response {@see receive()} will return.
     *
     * @param string $framedMessage Raw OPC UA frame, header included.
     * @return void
     */
    public function queueResponse(string $framedMessage): void
    {
        $this->responseQueue[] = $framedMessage;
    }
}
