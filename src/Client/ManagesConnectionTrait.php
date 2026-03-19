<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Exception\ConfigurationException;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;

trait ManagesConnectionTrait
{
    /**
     * @param string $endpointUrl
     */
    public function connect(string $endpointUrl): void
    {
        $parsed = parse_url($endpointUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new ConfigurationException("Invalid endpoint URL: {$endpointUrl}");
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? 4840;

        $isSecure = $this->securityPolicy !== SecurityPolicy::None
            && $this->securityMode !== SecurityMode::None;

        if ($isSecure && $this->serverCertDer === null) {
            $this->discoverServerCertificate($host, $port, $endpointUrl);
        }

        try {
            $this->transport->connect($host, $port, $this->getTimeout());

            $this->doHandshake($endpointUrl);

            $this->openSecureChannel();

            $this->createAndActivateSession($endpointUrl);
        } catch (ConnectionException $e) {
            $this->connectionState = ConnectionState::Broken;
            $this->lastEndpointUrl = $endpointUrl;
            throw $e;
        }

        $this->lastEndpointUrl = $endpointUrl;
        $this->connectionState = ConnectionState::Connected;
    }

    public function reconnect(): void
    {
        if ($this->lastEndpointUrl === null) {
            throw new ConfigurationException('Cannot reconnect: no previous connection endpoint. Call connect() first.');
        }
        
        $this->transport->close();
        $this->resetConnectionState();

        $this->connect($this->lastEndpointUrl);
    }

    public function disconnect(): void
    {
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
        $this->connectionState = ConnectionState::Disconnected;
    }

    public function isConnected(): bool
    {
        return $this->connectionState === ConnectionState::Connected;
    }

    public function getConnectionState(): ConnectionState
    {
        return $this->connectionState;
    }

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
        $this->authenticationToken = null;
        $this->secureChannelId = 0;
        $this->secureChannel = null;
        $this->serverNonce = null;
    }
}
