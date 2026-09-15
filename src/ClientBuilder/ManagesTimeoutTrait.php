<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ClientBuilder;

/**
 * Provides network timeout configuration for transport operations.
 */
trait ManagesTimeoutTrait
{
    private float $timeout = 5.0;

    private float $sessionTimeout = 120000.0;

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

    /**
     * Set the session timeout requested from the server.
     *
     * @param float $milliseconds Timeout in milliseconds; the server may revise it.
     * @return self
     */
    public function setSessionTimeout(float $milliseconds): self
    {
        $this->sessionTimeout = $milliseconds;

        return $this;
    }

    /**
     * Get the session timeout requested from the server.
     *
     * @return float Timeout in milliseconds.
     */
    public function getSessionTimeout(): float
    {
        return $this->sessionTimeout;
    }
}
