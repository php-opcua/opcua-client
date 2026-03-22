<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;

/**
 * Provides network timeout configuration for transport operations.
 */
trait ManagesTimeoutTrait
{
    private float $timeout;

    private function initTimeout(): void
    {
        $this->timeout = TcpTransport::DEFAULT_TIMEOUT;
    }

    /**
     * Set the network timeout for transport operations.
     *
     * @param float $timeout Timeout in seconds.
     * @return self
     */
    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Get the current network timeout.
     *
     * @return float Timeout in seconds.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }
}
