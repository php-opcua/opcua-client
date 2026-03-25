<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Types;

/**
 * Holds the result of an OPC UA SetTriggering operation.
 *
 * @see \Gianfriaur\OpcuaPhpClient\Client\ManagesSubscriptionsTrait::setTriggering()
 */
readonly class SetTriggeringResult
{
    /**
     * @param int[] $addResults Status codes for each link addition.
     * @param int[] $removeResults Status codes for each link removal.
     */
    public function __construct(
        public array $addResults,
        public array $removeResults,
    ) {
    }
}
