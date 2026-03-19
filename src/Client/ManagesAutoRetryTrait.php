<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

trait ManagesAutoRetryTrait
{
    private ?int $autoRetry = null;

    /**
     * @param int $maxRetries
     * @return static
     */
    public function setAutoRetry(int $maxRetries): self
    {
        $this->autoRetry = $maxRetries;

        return $this;
    }

    /**
     * @return int
     */
    public function getAutoRetry(): int
    {
        if ($this->autoRetry !== null) {
            return $this->autoRetry;
        }

        return $this->lastEndpointUrl !== null ? 1 : 0;
    }
}
