<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Client;

use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use Throwable;

/**
 * Provides runtime batch size resolution and server operation limit discovery for the connected client.
 */
trait ManagesBatchingRuntimeTrait
{
    /**
     * Get the configured batch size, or null if not explicitly set.
     *
     * @return int|null
     */
    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }

    /**
     * Get the server-reported maximum nodes per read operation, or null if unknown.
     *
     * @return int|null
     */
    public function getServerMaxNodesPerRead(): ?int
    {
        return $this->serverMaxNodesPerRead;
    }

    /**
     * Get the server-reported maximum nodes per write operation, or null if unknown.
     *
     * @return int|null
     */
    public function getServerMaxNodesPerWrite(): ?int
    {
        return $this->serverMaxNodesPerWrite;
    }

    /**
     * Compute the effective read batch size from explicit setting or server limits.
     *
     * @return int|null
     */
    public function getEffectiveReadBatchSize(): ?int
    {
        if ($this->batchSize !== null) {
            return $this->batchSize > 0 ? $this->batchSize : null;
        }

        return $this->serverMaxNodesPerRead;
    }

    /**
     * Compute the effective write batch size from explicit setting or server limits.
     *
     * @return int|null
     */
    public function getEffectiveWriteBatchSize(): ?int
    {
        if ($this->batchSize !== null) {
            return $this->batchSize > 0 ? $this->batchSize : null;
        }

        return $this->serverMaxNodesPerWrite;
    }

    /**
     * Discover server-reported operation limits via a readMulti call.
     *
     * @return void
     */
    private function discoverServerOperationLimits(): void
    {
        if ($this->batchSize === 0) {
            return;
        }

        $items = [
            ['nodeId' => NodeId::numeric(0, ServiceTypeId::MAX_NODES_PER_READ)],
            ['nodeId' => NodeId::numeric(0, ServiceTypeId::MAX_NODES_PER_WRITE)],
        ];

        try {
            $results = ($this->methodHandlers['readMulti'])($items);

            if (isset($results[0]) && StatusCode::isGood($results[0]->getStatusCode())) {
                $value = $results[0]->getValue();
                if (is_int($value) && $value > 0) {
                    $this->serverMaxNodesPerRead = $value;
                }
            }

            if (isset($results[1]) && StatusCode::isGood($results[1]->getStatusCode())) {
                $value = $results[1]->getValue();
                if (is_int($value) && $value > 0) {
                    $this->serverMaxNodesPerWrite = $value;
                }
            }
            $this->logger->debug('Server limits discovered: MaxNodesPerRead={read}, MaxNodesPerWrite={write}', $this->logContext([
                'read' => $this->serverMaxNodesPerRead,
                'write' => $this->serverMaxNodesPerWrite,
            ]));
        } catch (Throwable) {
            $this->logger->warning('Server does not support operation limits discovery', $this->logContext());
        }
    }

    /**
     * Reset discovered server operation limits.
     *
     * @return void
     */
    private function resetBatchingState(): void
    {
        $this->serverMaxNodesPerRead = null;
        $this->serverMaxNodesPerWrite = null;
    }
}
