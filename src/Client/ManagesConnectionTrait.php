<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use Closure;
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientConnecting;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ClientDisconnecting;
use PhpOpcua\Client\Event\ClientReconnecting;
use PhpOpcua\Client\Event\ConnectionFailed;
use PhpOpcua\Client\Event\RetryAttempt;
use PhpOpcua\Client\Event\RetryExhausted;
use PhpOpcua\Client\Exception\ConfigurationException;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\ConnectionState;

/**
 * Provides connection lifecycle management including connect, reconnect, disconnect, and automatic retry logic.
 */
trait ManagesConnectionTrait
{
    /**
     * Reconnect to the previously connected endpoint.
     *
     * @return void
     *
     * @throws ConnectionException If the reconnection attempt fails.
     *
     * @see self::disconnect()
     */
    public function reconnect(): void
    {
        $this->dispatch(fn () => new ClientReconnecting($this, $this->lastEndpointUrl));
        $this->logger->info('Reconnecting to {endpoint}', $this->logContext(['endpoint' => $this->lastEndpointUrl]));
        $this->transport->close();
        $this->resetConnectionState();

        $this->performConnect($this->lastEndpointUrl);
    }

    /**
     * Gracefully disconnect from the server, closing the session and secure channel.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->dispatch(fn () => new ClientDisconnecting($this, $this->lastEndpointUrl));
        $this->logger->info('Disconnecting', $this->logContext());
        if ($this->session !== null && $this->authenticationToken !== null) {
            try {
                $this->closeSession();
            } catch (OpcUaException) {
            }
        }

        if ($this->secureChannelId !== 0) {
            try {
                $this->closeSecureChannel();
            } catch (OpcUaException) {
            }
        }

        $this->transport->close();

        $this->resetConnectionState();
        $this->lastEndpointUrl = null;
        $this->connectionState = ConnectionState::Disconnected;
        $this->dispatch(fn () => new ClientDisconnected($this));
    }

    /**
     * Check whether the client is currently connected.
     *
     * @return bool True if the connection state is Connected, false otherwise.
     */
    public function isConnected(): bool
    {
        return $this->connectionState === ConnectionState::Connected;
    }

    /**
     * Get the current connection state.
     *
     * @return ConnectionState
     *
     * @see ConnectionState
     */
    public function getConnectionState(): ConnectionState
    {
        return $this->connectionState;
    }

    /**
     * Ensure the client is in a connected state.
     *
     * @return void
     *
     * @throws ConnectionException If the client is not connected.
     */
    private function ensureConnected(): void
    {
        if ($this->connectionState === ConnectionState::Connected) {
            return;
        }

        throw match ($this->connectionState) {
            ConnectionState::Disconnected => new ConnectionException('Not connected: call connect() first'),
            ConnectionState::Broken => new ConnectionException('Connection lost: call reconnect() or connect() to re-establish'),
            default => new ConnectionException('No explicit exception for state: ' . $this->connectionState->name),
        };
    }

    /**
     * Execute an operation with automatic retry on connection failure.
     *
     * @template T
     * @param Closure(): T $operation The operation to execute.
     * @return T
     *
     * @throws ConnectionException If all retry attempts are exhausted.
     */
    private function executeWithRetry(Closure $operation): mixed
    {
        $maxRetries = $this->autoRetry ?? 0;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $operation();
            } catch (ConnectionException $e) {
                $this->connectionState = ConnectionState::Broken;

                if ($attempt >= $maxRetries || $this->lastEndpointUrl === null) {
                    $this->dispatch(fn () => new RetryExhausted($this, $attempt + 1, $e));
                    $this->logger->error('Operation failed after {attempts} attempt(s): {message}', $this->logContext([
                        'attempts' => $attempt + 1,
                        'message' => $e->getMessage(),
                    ]));
                    throw $e;
                }

                $this->dispatch(fn () => new RetryAttempt($this, $attempt + 1, $maxRetries, $e));
                $this->logger->warning('Connection lost, retrying ({attempt}/{max})', $this->logContext([
                    'attempt' => $attempt + 1,
                    'max' => $maxRetries,
                ]));
                $this->reconnect();
            }
        }
    }

    /**
     * Perform the TCP connection, handshake, secure channel, and session establishment.
     *
     * @param string $endpointUrl The OPC UA endpoint URL.
     * @return void
     *
     * @throws ConfigurationException If the endpoint URL is invalid.
     * @throws ConnectionException If the TCP connection or handshake fails.
     */
    private function performConnect(string $endpointUrl): void
    {
        $parsed = parse_url($endpointUrl);
        if ($parsed === false || ! isset($parsed['host'])) {
            throw new ConfigurationException("Invalid endpoint URL: {$endpointUrl}");
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? 4840;

        $isSecure = $this->securityPolicy !== SecurityPolicy::None
            && $this->securityMode !== SecurityMode::None;

        if ($isSecure && $this->serverCertDer === null) {
            $this->discoverServerCertificate($host, $port, $endpointUrl);
        }

        if ($isSecure) {
            $this->validateServerCertificate();
        }

        $this->dispatch(fn () => new ClientConnecting($this, $endpointUrl));
        $this->logger->info('Connecting to {endpoint}', $this->logContext(['endpoint' => $endpointUrl]));

        try {
            $this->transport->connect($host, $port, $this->timeout);
            $this->logger->debug('TCP connection established to {host}:{port}', $this->logContext(['host' => $host, 'port' => $port]));

            $this->doHandshake($endpointUrl);
            $this->logger->debug('HEL/ACK handshake complete', $this->logContext());

            $this->openSecureChannel();
            $this->logger->debug('Secure channel opened (channelId={channelId})', $this->logContext(['channelId' => $this->secureChannelId]));

            $this->createAndActivateSession($endpointUrl);
            $this->logger->debug('Session created and activated', $this->logContext());
        } catch (ConnectionException $e) {
            $this->connectionState = ConnectionState::Broken;
            $this->lastEndpointUrl = $endpointUrl;
            $this->dispatch(fn () => new ConnectionFailed($this, $endpointUrl, $e));
            $this->logger->error('Connection failed: {message}', $this->logContext(['message' => $e->getMessage(), 'endpoint' => $endpointUrl]));
            throw $e;
        }

        $this->lastEndpointUrl = $endpointUrl;
        $this->connectionState = ConnectionState::Connected;

        $this->discoverServerOperationLimits();
        $this->logger->info('Connected to {endpoint}', $this->logContext(['endpoint' => $endpointUrl]));
        $this->dispatch(fn () => new ClientConnected($this, $endpointUrl));
    }

    /**
     * Reset internal connection state for a fresh connection attempt.
     *
     * @return void
     */
    private function resetConnectionState(): void
    {
        $this->session = null;
        $this->browseService = null;
        $this->readService = null;
        $this->writeService = null;
        $this->callService = null;
        $this->getEndpointsService = null;
        $this->subscriptionService = null;
        $this->monitoredItemService = null;
        $this->publishService = null;
        $this->historyReadService = null;
        $this->translateBrowsePathService = null;
        $this->nodeManagementService = null;
        $this->authenticationToken = null;
        $this->secureChannelId = 0;
        $this->secureChannel = null;
        $this->serverNonce = null;
        $this->resetBatchingState();
    }
}
