<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Transport;

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ProtocolException;

/**
 * Wire transport contract for the OPC UA binary protocol.
 *
 * Implementations move OPC UA framed messages (HEL/ACK, OPN, MSG, CLO, ERR)
 * between the {@see \PhpOpcua\Client\Client} and the server. The default
 * implementation is {@see TcpTransport} (`opc.tcp://`). Custom implementations
 * may target alternative wire encapsulations (`opc.tls://`, `opc.https://`,
 * `opc.wss://`, in-process loopback, …).
 *
 * Contract:
 *  - {@see connect()} establishes the transport. Subsequent calls without a
 *    prior {@see close()} are implementation-defined.
 *  - {@see send()} writes the entire payload. The framing (size header) is
 *    already present in `$data` — the transport is byte-level.
 *  - {@see receive()} returns exactly one complete OPC UA message, parsing
 *    the 8-byte standard header to determine the body length. It blocks until
 *    one full message arrives or the underlying transport errors out.
 *  - {@see setReceiveBufferSize()} caps the largest message the transport is
 *    willing to receive. The {@see \PhpOpcua\Client\Client} calls it after
 *    the HEL/ACK handshake with the server-negotiated chunk size.
 *  - {@see close()} releases the underlying resources. After this call,
 *    {@see isConnected()} returns `false` and {@see send()} / {@see receive()}
 *    must throw {@see ConnectionException}.
 *
 * Implementations are not required to be thread-safe — the `Client` owns the
 * transport for its entire lifetime and uses it from a single thread.
 */
interface ClientTransportInterface
{
    /**
     * Open the transport.
     *
     * @param string $host Server hostname or IP.
     * @param int $port Server port.
     * @param null|float $timeout Connect timeout in seconds. `null` means the
     *                            implementation default.
     * @return void
     *
     * @throws ConnectionException If the transport cannot be opened.
     */
    public function connect(string $host, int $port, null|float $timeout = null): void;

    /**
     * Send a complete OPC UA framed message to the server.
     *
     * @param string $data Raw bytes including the OPC UA standard header.
     * @return void
     *
     * @throws ConnectionException If the transport is not connected or the
     *                             write fails.
     */
    public function send(string $data): void;

    /**
     * Read exactly one complete OPC UA framed message from the server.
     *
     * Implementations parse the 8-byte standard header to determine the
     * message body length and block until the full message arrives.
     *
     * @return string The full message, including its 8-byte header.
     *
     * @throws ConnectionException If the transport is not connected, the
     *                             read fails, or the peer closed the
     *                             underlying channel.
     * @throws ProtocolException   If the framing is malformed (size out of
     *                             range, etc.).
     */
    public function receive(): string;

    /**
     * Cap the largest message the transport is willing to receive.
     *
     * Called by the client after the HEL/ACK handshake with the
     * server-negotiated maximum chunk size. Implementations that do not
     * impose a transport-side size limit (e.g. HTTPS over a chunked body)
     * may treat this as advisory.
     *
     * @param int $size Maximum size in bytes.
     * @return void
     */
    public function setReceiveBufferSize(int $size): void;

    /**
     * Close the transport and release any underlying resources.
     *
     * Safe to call multiple times; calls after the first are a no-op.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Whether the transport is currently open.
     *
     * @return bool
     */
    public function isConnected(): bool;
}
