<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Transport\TcpTransport;

trait ManagesTimeoutTrait
{
    private float $timeout;

    private function initTimeout(): void
    {
        $this->timeout = TcpTransport::DAFAUT_TIMEOUT;
    }

    /**
     * @param float $timeout
     * @return self
     */
    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }
}
